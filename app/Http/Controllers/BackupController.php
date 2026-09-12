<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
        'orders',
        'order_items',
        'purchase_orders',
        'purchase_order_items',
        'stock_movements',
        'customer_payments',
        'stock_returns',
    ];

    /** Reference/master data — always exported in full, even for a
     *  date-range backup, since the transactional rows in that range point
     *  back to these records (a purchase order needs its supplier to exist,
     *  an order needs its customer/cashier, etc). */
    private const MASTER_TABLES = ['users', 'products', 'customers', 'suppliers'];

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
            'purchase_order_items' => DB::table('purchase_order_items')->whereIn(
                'purchase_order_id',
                DB::table('purchase_orders')->whereBetween('created_at', [$from, $to])->pluck('id')
            ),
            default => DB::table($table)->whereBetween('created_at', [$from, $to]),
        };

        return $query->get()->map(fn ($row) => (array) $row)->all();
    }

    /** Restores an uploaded backup file. A "full" backup wipes and replaces
     *  the 12 managed tables. A "range" backup merges (upsert by id) so it
     *  never destroys data outside the backed-up window. */
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
     *  payments, stock returns, and users. Requires the "RESET" typed
     *  confirmation on the form on top of the usual are-you-sure dialog,
     *  since this cannot be undone from within the app (only by restoring
     *  a backup taken beforehand).
     *
     *  Wiping the users table would otherwise lock everyone out for good,
     *  so a single default admin account is recreated afterward — same
     *  credentials as a brand-new install — and the current session is
     *  ended so whoever resets it has to log back in with it. */
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

            DB::table('users')->insert([
                'name' => 'Admin',
                'username' => 'admin',
                'email' => 'admin@ubqari.pos',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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

        return redirect()->route('login')->with('status', 'Database has been fully reset. Log in with the default admin account — admin@ubqari.pos / password — and change the password right away.');
    }
}
