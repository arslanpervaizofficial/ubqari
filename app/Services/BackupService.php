<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for the Ubqari POS backup export (Feature 7).
 *
 * Both BackupController::download() (the manual download button) and the
 * scheduled artisan command (backup:scheduled) build their payload through
 * this service, so a scheduled backup file is byte-for-byte the same
 * format as a manually downloaded full backup: a JSON document
 *   { "meta": {...}, "tables": { "<table>": [ rows... ] } }
 * encoded with JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE.
 *
 * The table list is the v2.2 list (see BackupController's history): the
 * original list plus purchase_order_drafts (v2.2 F9), order_payments and
 * app_settings (v2 H18) — plus, in v3.1.0 (H2), every v3 table that
 * was missing (supplier_payments, product_batches, stock_counts,
 * stock_count_items, audit_logs, sms_logs, journal_entries,
 * journal_lines, online_payments), checked against migrations
 * 000003–000010. Framework-internal tables (sessions, cache, jobs,
 * migrations, password_reset_tokens) are deliberately excluded —
 * restoring those would fight the running app instead of restoring
 * business data.
 *
 * v3.1.0 (H3) ENCRYPTION: encodeBackup() / decodeBackup() are the only
 * way a backup payload leaves or enters the system. The payload is
 * encrypted with Laravel Crypt (APP_KEY) and wrapped as
 *   { "meta": {..., "encrypted": true}, "payload": "<encrypted string>" }
 * so a downloaded file or a scheduled file on disk never contains
 * business data — or app_settings secrets (SMS key, gateway
 * credentials) — in plain text. decodeBackup() still accepts OLD
 * unencrypted backups ({ "meta": ..., "tables": ... }) unchanged, so
 * backups taken before v3.1.0 still restore. WARNING (for the README):
 * losing APP_KEY makes encrypted backups UNRECOVERABLE — there is no
 * fallback key and no way to decrypt them afterwards, so keep a copy
 * of the key with the backups, not only inside the app.
 *
 * Filesystem note: scheduled files are written with storage_path() to
 * storage/app/backups, NOT through Storage::disk('local') — in this
 * Laravel 12 app the local disk root is storage/app/private, so the
 * disk would have put them somewhere else entirely.
 */
class BackupService
{
    /**
     * Every application data table that participates in backup/restore, in
     * an order that's safe to insert in (parents before the children that
     * reference them).
     */
    public const TABLES = [
        'users',
        'products',
        'product_price_histories',
        'customers',
        'suppliers',
        // v2.2 (F9): PO drafts were never backed up, restored or
        // reset, so stale drafts survived a restore/reset and the PO
        // create page auto-restored them. Drafts sit after their
        // parents (users, suppliers) so inserts stay in safe order.
        'purchase_order_drafts',
        'orders',
        'order_items',
        // v2 (H18): order_payments used to be missing from this
        // list, so a "full" backup silently dropped the whole order
        // ledger and a restore left old order_payments rows sitting
        // against restored orders whose paid/due amounts no longer
        // matched them. It is scoped through its parent order in
        // rangeRows() below, like order_items.
        'order_payments',
        'purchase_orders',
        'purchase_order_items',
        'stock_movements',
        'customer_payments',
        'stock_returns',
        'expenses',
        'cash_parties',
        'cash_transactions',
        // v3.1.0 (H2): every v3 table, in insert-safe order. Parents
        // sit before the children that reference them: supplier_payments
        // needs suppliers; product_batches needs products and
        // purchase_orders; stock_count_items needs stock_counts and
        // products; audit_logs/sms_logs need users/customers (nullable);
        // journal_lines needs journal_entries; online_payments needs
        // orders. Restore additionally disables foreign-key checks (see
        // BackupController), so this order is a second safety net, not
        // the only one.
        'supplier_payments',
        'product_batches',
        'stock_counts',
        'stock_count_items',
        'audit_logs',
        'sms_logs',
        'journal_entries',
        'journal_lines',
        'online_payments',
        // v2 (H18): app_settings was also missing, even though
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
    public const MASTER_TABLES = ['users', 'products', 'customers', 'suppliers', 'cash_parties'];

    /** Subdirectory of storage/app/ holding scheduled backup files. */
    public const DIRECTORY = 'backups';

    /** How many scheduled backup files to keep; older ones are pruned. */
    public const KEEP = 7;

    /** Filename prefix for files this service writes (and the only files
     *  prune() will ever delete). */
    public const FILE_PREFIX = 'backup-';

    /**
     * The full-export payload: every row in every table, plus meta.
     * This is exactly what BackupController::download() used to build
     * inline for ?type=full — same tables, same order, same meta keys.
     */
    public function fullExport(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return [
            'meta' => [
                'app' => 'Ubqari POS',
                'type' => 'full',
                'generated_at' => now()->toDateTimeString(),
            ],
            'tables' => $tables,
        ];
    }

    /**
     * The range-export payload: master data in full plus transactional
     * rows scoped to [$from, $to]. Same shape as fullExport(), with the
     * from/to dates added to meta — exactly what download() used to
     * build inline for ?type=range.
     */
    public function rangeExport($from, $to): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            if (!in_array($table, self::MASTER_TABLES, true)) {
                $tables[$table] = $this->rangeRows($table, $from, $to);
            } else {
                $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            }
        }

        return [
            'meta' => [
                'app' => 'Ubqari POS',
                'type' => 'range',
                'generated_at' => now()->toDateTimeString(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'tables' => $tables,
        ];
    }

    /** Rows for a transactional table that fall within [from, to], scoped by
     *  the table's own created_at — except the line-item tables, which
     *  have no date of their own and are scoped through their parent
     *  order/purchase-order instead. */
    private function rangeRows(string $table, $from, $to): array
    {
        $query = match ($table) {
            'order_items' => DB::table('order_items')->whereIn(
                'order_id',
                DB::table('orders')->whereBetween('created_at', [$from, $to])->pluck('id')
            ),
            // v2 (H18): order ledger rows have no date of their
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
            // v3.1.0 (H2): line-item tables of v3, scoped through their
            // parent like order_items above. Every other v3 table has
            // its own created_at and uses the default branch below.
            'stock_count_items' => DB::table('stock_count_items')->whereIn(
                'stock_count_id',
                DB::table('stock_counts')->whereBetween('created_at', [$from, $to])->pluck('id')
            ),
            'journal_lines' => DB::table('journal_lines')->whereIn(
                'journal_entry_id',
                DB::table('journal_entries')->whereBetween('created_at', [$from, $to])->pluck('id')
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

    /**
     * v3.1.0 (H3): encode a backup payload for storage/download.
     * Returns a JSON string: the payload's own meta (plus
     * encrypted=true) alongside the Crypt-encrypted payload JSON.
     * The inner JSON is encoded with the same flags fullExport() used
     * to use for the outer document, so a decrypted payload is
     * byte-identical in shape to a pre-v3.1.0 backup.
     */
    public function encodeBackup(array $payload): string
    {
        $meta = $payload['meta'] ?? [];
        $meta['encrypted'] = true;
        $inner = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($inner === false) {
            throw new \RuntimeException('Failed to encode backup JSON: ' . json_last_error_msg());
        }

        $wrapped = json_encode(['meta' => $meta, 'payload' => Crypt::encryptString($inner)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($wrapped === false) {
            throw new \RuntimeException('Failed to encode encrypted backup JSON: ' . json_last_error_msg());
        }

        return $wrapped;
    }

    /**
     * v3.1.0 (H3): decode a backup document back to its payload, or
     * null when it is neither format. Accepts, in order:
     *   1. an encrypted wrapper ({ "meta": ..., "payload": "..." }) —
     *      decrypts with Crypt (APP_KEY) and decodes the inner JSON;
     *   2. a plain pre-v3.1.0 backup ({ "meta": ..., "tables": ... })
     *      — returned as-is (decrypt-or-plain fallback);
     *   3. a bare encrypted string (no JSON at all) — decrypted, then
     *      decoded. This covers a file saved from an encrypted string
     *      directly rather than through encodeBackup().
     * A wrapper whose payload fails to decrypt (wrong APP_KEY, corrupt
     * file) returns null — the caller reports it as an invalid backup
     * rather than half-restoring anything. Note: a decrypt failure
     * caused by a lost/changed APP_KEY is unrecoverable by design.
     */
    public function decodeBackup(string $content): ?array
    {
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['payload']) && is_string($data['payload'])) {
            try {
                $inner = Crypt::decryptString($data['payload']);
            } catch (\Throwable $e) {
                return null;
            }
            $payload = json_decode($inner, true);
            if (!is_array($payload) || !isset($payload['meta']['type']) || !isset($payload['tables']) || !is_array($payload['tables'])) {
                return null;
            }

            return $payload;
        }

        if (is_array($data) && isset($data['meta']['type']) && isset($data['tables']) && is_array($data['tables'])) {
            return $data;
        }

        // Bare encrypted string fallback.
        try {
            $inner = Crypt::decryptString(trim($content));
        } catch (\Throwable $e) {
            return null;
        }
        $payload = json_decode($inner, true);
        if (!is_array($payload) || !isset($payload['meta']['type']) || !isset($payload['tables']) || !is_array($payload['tables'])) {
            return null;
        }

        return $payload;
    }

    /** Absolute path of the scheduled-backups directory. */
    public function directoryPath(): string
    {
        return storage_path('app/' . self::DIRECTORY);
    }

    /** Dated filename for a scheduled backup, e.g. backup-2026-10-29-020000.json. */
    public function scheduledFilename($at = null): string
    {
        $at = $at ?? now();

        return self::FILE_PREFIX . $at->format('Y-m-d-His') . '.json';
    }

    /**
     * Write a full export to storage/app/backups/ and prune older
     * scheduled files down to KEEP. Returns filename, path, byte size
     * and the list of pruned filenames.
     *
     * Safety rules: the file is written to a temporary name in the same
     * directory and renamed into place, so a crash mid-write never
     * leaves a half-written .json behind; prune() runs only AFTER a
     * successful write, so a failed export never deletes old backups;
     * and prune() only ever deletes files matching our own
     * backup-*.json pattern inside this directory — never
     * subdirectories, never other filenames, never anything outside it.
     */
    public function writeScheduledBackup(): array
    {
        $dir = $this->directoryPath();

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create backup directory: {$dir}");
        }

        // Clear any temp file a crashed earlier run may have left behind.
        foreach (glob($dir . '/.' . self::FILE_PREFIX . '*.tmp') ?: [] as $stale) {
            if (is_file($stale)) {
                @unlink($stale);
            }
        }

        $payload = $this->fullExport();
        // v3.1.0 (H3): scheduled files are encrypted, like downloads.
        $json = $this->encodeBackup($payload);

        // Same-second rerun must not silently overwrite the earlier file.
        $filename = $this->scheduledFilename();
        $path = $dir . '/' . $filename;
        $suffix = 2;
        while (file_exists($path)) {
            $filename = self::FILE_PREFIX . now()->format('Y-m-d-His') . '-' . $suffix . '.json';
            $path = $dir . '/' . $filename;
            $suffix++;
        }

        $tmpPath = $dir . '/.' . $filename . '.tmp';
        if (file_put_contents($tmpPath, $json) === false) {
            throw new \RuntimeException("Could not write backup file: {$path}");
        }
        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Could not move backup file into place: {$path}");
        }

        $pruned = $this->prune();

        return [
            'filename' => $filename,
            'path' => $path,
            'bytes' => filesize($path) ?: strlen($json),
            'pruned' => $pruned,
        ];
    }

    /**
     * Delete scheduled backup files older than the newest $keep, newest
     * first by modification time (filename breaks ties). Only files
     * matching backup-*.json in the backups directory are considered;
     * anything else in that directory is left alone. Returns the
     * filenames that were deleted.
     */
    public function prune(int $keep = self::KEEP): array
    {
        $dir = $this->directoryPath();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob($dir . '/' . self::FILE_PREFIX . '*.json') ?: [] as $path) {
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        usort($files, function ($a, $b) {
            $timeDiff = filemtime($b) <=> filemtime($a);
            if ($timeDiff !== 0) {
                return $timeDiff;
            }

            return strcmp(basename($b), basename($a));
        });

        $deleted = [];
        foreach (array_slice($files, $keep) as $path) {
            // Belt and braces: only ever unlink a plain file that still
            // sits directly inside our own backups directory.
            if (is_file($path) && dirname($path) === $dir && str_starts_with(basename($path), self::FILE_PREFIX)) {
                if (@unlink($path)) {
                    $deleted[] = basename($path);
                }
            }
        }

        return $deleted;
    }

    /**
     * Scheduled backup files, newest first. Each entry: filename, size
     * in bytes, human-readable size, and modification time (Carbon).
     * Returns [] when the directory does not exist or is empty.
     */
    public function listScheduledBackups(): array
    {
        $dir = $this->directoryPath();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob($dir . '/' . self::FILE_PREFIX . '*.json') ?: [] as $path) {
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        usort($files, function ($a, $b) {
            $timeDiff = filemtime($b) <=> filemtime($a);
            if ($timeDiff !== 0) {
                return $timeDiff;
            }

            return strcmp(basename($b), basename($a));
        });

        return array_map(function ($path) {
            $size = filesize($path) ?: 0;

            return [
                'filename' => basename($path),
                'size' => $size,
                'size_human' => self::humanSize($size),
                'modified_at' => Carbon::createFromTimestamp(filemtime($path) ?: 0),
            ];
        }, $files);
    }

    /** Newest scheduled backup file, or null when none exists yet. */
    public function latestScheduledBackup(): ?array
    {
        return $this->listScheduledBackups()[0] ?? null;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
