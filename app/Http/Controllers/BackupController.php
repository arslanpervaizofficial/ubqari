<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackupController extends Controller
{
    /**
     * Every application data table that participates in backup/restore, in
     * an order that's safe to insert in (parents before the children that
     * reference them). Framework-internal tables (sessions, cache, jobs,
     * migrations, password_reset_tokens) are deliberately excluded —
     * restoring those would fight the running app instead of restoring
     * business data.
     */
    private const TABLES = [
        'users',
        'products',
        'product_price_histories',
        'customers',
        'suppliers',
        // v2.2 (F9): PO drafts were never backed up, restored or
        // reset, so stale drafts survived a restore/reset and the PO
        // create page auto-restored them. Drafts sit after their
        // parents (users, suppliers) so inserts stay in safe order.
        // reset() deletes every table in this list, so drafts are
        // wiped by reset automatically. Range backups scope drafts by
        // created_at (the default branch in rangeRows()).
        'purchase_order_drafts',
        'orders',
        'order_items',
        // FIXED (v2, H18): order_payments used to be missing from this
        // list, so a "full" backup silently dropped the whole order
        // ledger (walk-in order payments) and a restore left the old
        // order_payments rows sitting against restored orders whose
        // paid/due amounts no longer matched them. It is scoped through
        // its parent order in rangeRows() below, like order_items.
        'order_payments',
        'purchase_orders',
        'purchase_order_items',
        'stock_movements',
        'customer_payments',
        'stock_returns',
        'expenses',
        'cash_parties',
        'cash_transactions',
        // FIXED (v2, H18): app_settings was also missing, even though
        // reset() deletes it — so a full restore never brought settings
        // back. It has no foreign keys, so it sits last in insert order.
        'app_settings',
    ];

    /** Reference/master data — always exported in full, even for a
     *  date-range backup, since the transactional rows in that range point
     *  back to these records (a purchase order needs its supplier to exist,
     *  an order needs its customer/cashier, etc). cash_parties is the same
     *  situation for cash_transactions as customers/suppliers are for
     *  orders/purchase orders. */
    private const MASTER_TABLES = ['users', 'products', 'customers', 'suppliers', 'cash_parties'];

    public function index()
    {
        return view('backup.index');
    }

    /** Streams a JSON backup file — either every row in every table
     *  (?type=full), or master data in full plus transactional rows scoped
     *  to a date range (?type=range&from=&to=). */
    public function download(Request $request)
    {
        $type = $request->input('type') === 'range' ? 'range' : 'full';

        $from = $to = null;
        if ($type === 'range') {
            $request->validate([
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after_or_equal:from'],
            ]);
            $from = $request->date('from')->startOfDay();
            $to = $request->date('to')->endOfDay();
        }

        $tables = [];
        foreach (self::TABLES as $table) {
            if ($type === 'range' && !in_array($table, self::MASTER_TABLES, true)) {
                $tables[$table] = $this->rangeRows($table, $from, $to);
            } else {
                $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            }
        }

        $meta = [
            'app' => 'Ubqari POS',
            'type' => $type,
            'generated_at' => now()->toDateTimeString(),
        ];

        if ($type === 'range') {
            $meta['from'] = $from->toDateString();
            $meta['to'] = $to->toDateString();
            $filename = "ubqari-pos-backup-{$meta['from']}_to_{$meta['to']}.json";
        } else {
            $filename = 'ubqari-pos-backup-full-' . now()->format('Y-m-d_His') . '.json';
        }

        $json = json_encode(['meta' => $meta, 'tables' => $tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Rows for a transactional table that fall within [from, to], scoped by
     *  the table's own created_at — except the two line-item tables, which
     *  have no date of their own and are scoped through their parent
     *  order/purchase-order instead. */
    private function rangeRows(string $table, $from, $to): array
    {
        $query = match ($table) {
            'order_items' => DB::table('order_items')->whereIn(
                'order_id',
                DB::table('orders')->whereBetween('created_at', [$from, $to])->pluck('id')
            ),
            // FIXED (v2, H18): order ledger rows have no date of their
            // own, so like order_items they are scoped through their
            // parent order's created_at.
            'order_payments' => DB::table('order_payments')->whereIn(
                'order_id',
                DB::table('orders')->whereBetween('created_at', [$from, $to])->pluck('id')
            ),
            'purchase_order_items' => DB::table('purchase_order_items')->whereIn(
                'purchase_order_id',
                DB::table('purchase_orders')->whereBetween('created_at', [$from, $to])->pluck('id')
            ),
            // These two have their own business date, separate from
            // created_at (an expense/cash entry can be logged today for an
            // earlier date) — scope by that instead, same reasoning as
            // orders/purchase orders using created_at being the wrong
            // column for line items above.
            'expenses' => DB::table('expenses')->whereBetween('expense_date', [$from, $to]),
            'cash_transactions' => DB::table('cash_transactions')->whereBetween('transaction_date', [$from, $to]),
            default => DB::table($table)->whereBetween('created_at', [$from, $to]),
        };

        return $query->get()->map(fn ($row) => (array) $row)->all();
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
        $request->validate([
            'backup_file' => ['required', 'file', 'max:51200'], // 50MB
        ]);

        $content = file_get_contents($request->file('backup_file')->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['meta']['type']) || !isset($data['tables']) || !is_array($data['tables'])) {
            return back()->withErrors(['backup_file' => 'This file does not look like a valid Ubqari POS backup.']);
        }

        $type = $data['meta']['type'] === 'range' ? 'range' : 'full';

        // Only ever touch tables we recognise — never let an uploaded file
        // dictate arbitrary table names.
        $tables = array_intersect_key($data['tables'], array_flip(self::TABLES));

        // FIXED (v2, H19): for range restores, work out once which
        // master rows the range's transactional rows actually point at
        // (see rangeReferencedMasters() below). Anything else in the
        // file's master sections is ignored in range mode.
        $referencedMasters = $type === 'range' ? $this->rangeReferencedMasters($tables) : [];

        DB::beginTransaction();
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach (self::TABLES as $table) {
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
            return back()->withErrors(['backup_file' => 'Restore failed, nothing was changed: ' . $e->getMessage()]);
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
        $request->validate([
            'confirm' => ['required', 'in:RESET'],
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
            foreach (self::TABLES as $table) {
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
            return back()->withErrors(['confirm' => 'Reset failed, nothing was changed: ' . $e->getMessage()]);
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
