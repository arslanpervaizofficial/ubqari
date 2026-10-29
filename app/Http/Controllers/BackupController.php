<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackupController extends Controller
{
    // The backup table list lives in App\Services\BackupService::TABLES
    // (and ::MASTER_TABLES) — single source of truth shared with the
    // scheduled backup command, so a manual download and a scheduled
    // file can never drift apart. See BackupService for the list and
    // the reasons each table is (or is not) in it.

    public function index()
    {
        $service = app(BackupService::class);

        return view('backup.index', [
            'scheduledBackups' => $service->listScheduledBackups(),
            'latestScheduledBackup' => $service->latestScheduledBackup(),
        ]);
    }

    /** Streams a JSON backup file — either every row in every table
     *  (?type=full), or master data in full plus transactional rows scoped
     *  to a date range (?type=range&from=&to=).
     *
     *  Feature 7: the payload is built by BackupService — the same code
     *  path the scheduled command uses — so a manual download and a
     *  scheduled file are the same export. Filenames and response
     *  headers below are unchanged from before.
     *
     *  v3.1.0 (H3): the streamed file is ENCRYPTED (see
     *  BackupService::encodeBackup) — it is still a .json document,
     *  but its tables live inside an encrypted payload string, never
     *  in plain text. Only this app (with its APP_KEY) can restore it. */
    public function download(Request $request)
    {
        $type = $request->input('type') === 'range' ? 'range' : 'full';

        $service = app(BackupService::class);

        if ($type === 'range') {
            $request->validate([
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after_or_equal:from'],
            ]);
            $from = $request->date('from')->startOfDay();
            $to = $request->date('to')->endOfDay();

            $payload = $service->rangeExport($from, $to);
            $filename = "ubqari-pos-backup-{$payload['meta']['from']}_to_{$payload['meta']['to']}.json";
        } else {
            $payload = $service->fullExport();
            $filename = 'ubqari-pos-backup-full-' . now()->format('Y-m-d_His') . '.json';
        }

        $json = $service->encodeBackup($payload);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** For a range restore: the ids of master rows that the range's own
     *  transactional rows in the uploaded file actually reference.
     *  Built from the FILE's rows (not the live database), so a master
     *  row is only ever restored when the restored window genuinely
     *  needs it to exist:
     *   - customers: orders.customer_id, stock_returns.customer_id,
     *     customer_payments.customer_id
     *   - suppliers: purchase_orders.supplier_id,
     *     stock_returns.supplier_id
     *   - products: order_items.product_id,
     *     purchase_order_items.product_id, stock_movements.product_id,
     *     stock_returns.product_id
     *   - cash_parties: cash_transactions.cash_party_id
     *  users are deliberately absent: they are never merged in range
     *  mode at all, so no referenced-users set is built. Rows in the
     *  file with no id, or referencing columns that are null, are
     *  ignored. */
    private function rangeReferencedMasters(array $tables): array
    {
        $referenced = [
            'products' => [],
            'customers' => [],
            'suppliers' => [],
            'cash_parties' => [],
        ];
        $collect = function (string $table, string $column, string $master) use ($tables, &$referenced) {
            foreach ($tables[$table] ?? [] as $row) {
                if (is_array($row) && isset($row[$column]) && $row[$column] !== null) {
                    $referenced[$master][] = $row[$column];
                }
            }
        };
        $collect('orders', 'customer_id', 'customers');
        $collect('stock_returns', 'customer_id', 'customers');
        $collect('customer_payments', 'customer_id', 'customers');
        $collect('purchase_orders', 'supplier_id', 'suppliers');
        $collect('stock_returns', 'supplier_id', 'suppliers');
        $collect('order_items', 'product_id', 'products');
        $collect('purchase_order_items', 'product_id', 'products');
        $collect('stock_movements', 'product_id', 'products');
        $collect('stock_returns', 'product_id', 'products');
        $collect('cash_transactions', 'cash_party_id', 'cash_parties');
        foreach ($referenced as $master => $ids) {
            $referenced[$master] = array_values(array_unique($ids));
        }
        return $referenced;
    }

    /** Restores an uploaded backup file. A "full" backup wipes and replaces
     *  the managed tables. A "range" backup merges (upsert by id) so it
     *  never destroys data outside the backed-up window — with two
     *  FIXED (v2, H19) limits on that merge: the users table is NEVER
     *  upserted in range mode (restoring a week's sales used to revert
     *  password changes and reactivate deactivated users), and master
     *  tables (products, customers, suppliers, cash_parties) are only
     *  upserted for rows actually referenced by the range's own
     *  transactional rows in the file, instead of every master row the
     *  file happens to carry in full. */
    public function restore(Request $request)
    {
        // v3.1.0 (L5): destructive action — the admin must re-enter
        // their current password (the 'current_password' rule checks it
        // against the logged-in user) before any restore runs.
        $request->validate([
            'backup_file' => ['required', 'file', 'max:51200'], // 50MB
            'current_password' => ['required', 'current_password'],
        ]);

        $content = file_get_contents($request->file('backup_file')->getRealPath());
        // v3.1.0 (H3): decrypt-or-plain — encrypted v3.1.0 backups are
        // decrypted with APP_KEY, and OLD unencrypted backups still
        // restore unchanged (see BackupService::decodeBackup). A file
        // that decrypts to nothing (wrong key, corrupt file) is
        // rejected here, before any table is touched.
        $data = app(BackupService::class)->decodeBackup($content);

        if (!is_array($data) || !isset($data['meta']['type']) || !isset($data['tables']) || !is_array($data['tables'])) {
            return back()->withErrors(['backup_file' => 'This file does not look like a valid Ubqari POS backup, or it was encrypted with a different app key.']);
        }

        $type = $data['meta']['type'] === 'range' ? 'range' : 'full';

        // Only ever touch tables we recognise — never let an uploaded file
        // dictate arbitrary table names.
        $tables = array_intersect_key($data['tables'], array_flip(BackupService::TABLES));

        // FIXED (v2, H19): for range restores, work out once which
        // master rows the range's transactional rows actually point at
        // (see rangeReferencedMasters() below). Anything else in the
        // file's master sections is ignored in range mode.
        $referencedMasters = $type === 'range' ? $this->rangeReferencedMasters($tables) : [];

        DB::beginTransaction();
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach (BackupService::TABLES as $table) {
                if (!array_key_exists($table, $tables) || !is_array($tables[$table])) {
                    continue;
                }

                if ($type === 'full') {
                    // DELETE, not TRUNCATE — TRUNCATE issues an implicit
                    // commit in MySQL/InnoDB, which silently breaks the
                    // DB::transaction() below: if a later table's insert
                    // threw, DB::rollBack() would have nothing left to undo
                    // for every table already truncated before that point,
                    // leaving a half-restored database. DELETE participates
                    // in the transaction properly, so a failure partway
                    // through genuinely undoes everything in this restore.
                    DB::table($table)->delete();
                    foreach (array_chunk($tables[$table], 500) as $chunk) {
                        if ($chunk) DB::table($table)->insert($chunk);
                    }
                } else {
                    // Merge instead of wiping, so a partial/range restore
                    // never destroys data outside the backed-up window.
                    // FIXED (v2, H19): users are never merged in range
                    // mode, and master rows are merged only when the
                    // range's own transactional rows reference them.
                    if ($table === 'users') {
                        continue;
                    }
                    if (in_array($table, ['products', 'customers', 'suppliers', 'cash_parties'], true)) {
                        $allowedIds = $referencedMasters[$table] ?? [];
                        foreach ($tables[$table] as $row) {
                            if (!is_array($row) || !isset($row['id'])) continue;
                            if (!in_array($row['id'], $allowedIds, false)) continue;
                            DB::table($table)->updateOrInsert(['id' => $row['id']], $row);
                        }
                        continue;
                    }
                    foreach ($tables[$table] as $row) {
                        if (!is_array($row) || !isset($row['id'])) continue;
                        DB::table($table)->updateOrInsert(['id' => $row['id']], $row);
                    }
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            // v3.1.0 (L5): never leak internals (table/column details)
            // to the browser — generic message here, detail in the log.
            Log::error('Backup restore failed.', ['exception' => $e->getMessage()]);
            return back()->withErrors(['backup_file' => 'Restore failed, nothing was changed.']);
        }

        // v2.2 (F9): drop drafts whose user or supplier no longer
        // resolves — otherwise a restored database can hand the PO
        // create page a draft for a deleted supplier, or a user_id
        // that now belongs to someone else. Null ids are allowed
        // (drafts can be ownerless), so only non-null dangling
        // references are removed. Guarded by hasTable so restores on
        // installs without the drafts table still work.
        if (Schema::hasTable('purchase_order_drafts')) {
            DB::table('purchase_order_drafts')
                ->where(function ($q) {
                    $q->whereNotNull('user_id')->whereNotExists(function ($sq) {
                        $sq->select(DB::raw(1))->from('users')->whereColumn('users.id', 'purchase_order_drafts.user_id');
                    });
                })
                ->orWhere(function ($q) {
                    $q->whereNotNull('supplier_id')->whereNotExists(function ($sq) {
                        $sq->select(DB::raw(1))->from('suppliers')->whereColumn('suppliers.id', 'purchase_order_drafts.supplier_id');
                    });
                })
                ->delete();
        }

        if ($type === 'full') {
            // The users table was just replaced wholesale — the current
            // session's account may no longer exist, so start fresh rather
            // than risk operating as a half-valid logged-in user.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->with('status', 'Full backup restored successfully. Please log in again.');
        }

        return back()->with('status', 'Backup restored successfully (' . ($data['meta']['from'] ?? '?') . ' to ' . ($data['meta']['to'] ?? '?') . ').');
    }

    /** Danger zone: wipes every managed table completely — products,
     *  customers, suppliers, orders, purchase orders, stock movements,
     *  payments, stock returns, expenses, cash management, and users.
     *  Requires the "RESET" typed
     *  confirmation on the form on top of the usual are-you-sure dialog,
     *  since this cannot be undone from within the app (only by restoring
     *  a backup taken beforehand).
     *
     *  Wiping the users table would otherwise lock everyone out for good,
     *  so a single admin account is recreated afterward — but FIXED
     *  (v2, H20): it NO LONGER uses a known default password, and the
     *  credentials are NO LONGER printed on the backup page or in a
     *  reusable flash message. Instead a random one-time password is
     *  generated, shown exactly once in the flash message after the
     *  reset, and the account is flagged must_change_password (when
     *  that column exists — guarded by Schema::hasColumn so this file
     *  still works before the migration that adds the column is run).
     *  The current session is ended so whoever resets it has to log
     *  back in with the one-time password. */
    public function reset(Request $request)
    {
        // v3.1.0 (L5): same current-password re-entry as restore() —
        // wiping the whole database must never ride on a stale session
        // alone. The form field lives in backup/index.blade.php.
        $request->validate([
            'confirm' => ['required', 'in:RESET'],
            'current_password' => ['required', 'current_password'],
        ]);

        DB::beginTransaction();
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // DELETE, not TRUNCATE — TRUNCATE issues an implicit commit in
            // MySQL/InnoDB, so wrapping it in DB::transaction() here was
            // misleading: if anything below threw (e.g. the admin-account
            // insert), DB::rollBack() had nothing left to undo for every
            // table already truncated before that point. That's exactly
            // what "products/orders reset but Reports/Customer balance
            // didn't" looked like — some tables cleared, others (further
            // down the list, or unlucky in execution order) didn't, with
            // no real rollback protecting against the mismatch. DELETE
            // participates in the transaction properly: either every table
            // below is cleared and the fresh admin account is inserted
            // together, or none of it is.
            foreach (BackupService::TABLES as $table) {
                DB::table($table)->delete();
            }
            DB::table('app_settings')->delete();

            // FIXED (v2, H20): one-time random password, never a known
            // default. It is returned to the caller below and shown once
            // in the flash message; it is not stored anywhere in plain
            // text and is not printed on any page.
            $oneTimePassword = Str::random(16);
            $admin = [
                'name' => 'Admin',
                'username' => 'admin',
                'email' => 'admin@ubqari.pos',
                'password' => Hash::make($oneTimePassword),
                'role' => 'admin',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // Set by the separate migration agent; guarded so reset()
            // does not crash on databases where the column is absent.
            if (Schema::hasColumn('users', 'must_change_password')) {
                $admin['must_change_password'] = true;
            }
            DB::table('users')->insert($admin);

            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            // v3.1.0 (L5): generic message to the browser, detail logged.
            Log::error('Database reset failed.', ['exception' => $e->getMessage()]);
            return back()->withErrors(['confirm' => 'Reset failed, nothing was changed.']);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Shown exactly once, here and nowhere else (not on the backup
        // page, not in any reusable message). The admin must change it
        // on first login (must_change_password flag above).
        return redirect()->route('login')->with('status', 'Database has been fully reset. Your one-time admin password is: ' . $oneTimePassword . ' — copy it now, it will not be shown again. Log in as admin@ubqari.pos and change the password right away.');
    }
}
