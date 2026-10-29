<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Daily scheduled backup (Feature 7).
 *
 * Writes the SAME full JSON export that BackupController::download()
 * produces (same tables, same format, via BackupService) to
 * storage/app/backups/ with a dated filename, then prunes that
 * directory to the last 7 scheduled files.
 *
 * Runs from the server cron with no user logged in: it uses no auth,
 * no session and no request — only the database and the filesystem.
 * It never deletes anything outside storage/app/backups (see
 * BackupService::prune()), and a failed write prunes nothing, so old
 * backups are never lost because a new export failed.
 *
 * Requires the server cron line (see routes/console.php):
 *   * * * * * php artisan schedule:run
 * Without it, the schedule in routes/console.php never fires.
 */
class RunScheduledBackup extends Command
{
    protected $signature = 'backup:scheduled';

    protected $description = 'Write a full JSON backup to storage/app/backups and prune older scheduled backups, keeping only the last 7.';

    public function handle(BackupService $service): int
    {
        try {
            $result = $service->writeScheduledBackup();
        } catch (\Throwable $e) {
            $this->error('Scheduled backup failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $prunedCount = count($result['pruned']);
        $this->info("Scheduled backup written: {$result['filename']} ({$result['bytes']} bytes). Pruned {$prunedCount} old backup(s).");

        return self::SUCCESS;
    }
}
