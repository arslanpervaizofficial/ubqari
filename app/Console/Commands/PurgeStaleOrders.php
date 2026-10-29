<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

/**
 * v3.1.0 (M15) — hourly purge of stale in-progress POS carts.
 *
 * Order::purgeStaleInProgress() used to be called only from the report
 * controllers (ReportController / ReportExportController), so it ran
 * only when someone happened to open a report — never from POS, the
 * dashboard, or on any schedule. This command gives the scheduler
 * (routes/console.php: orders:purge-stale, hourly) something to run.
 * The ReportController calls are kept — they are harmless duplicates.
 *
 * Behaviour is the method's own, unchanged here: carts untouched for
 * over an hour become 'hold' if they have items, or are permanently
 * discarded (forceDelete, never Trash) if empty. An actively-used cart
 * is never touched.
 *
 * Requires the server cron line (same one the scheduled backup needs):
 *   * * * * * php artisan schedule:run
 * Without it, the hourly schedule in routes/console.php never fires.
 */
class PurgeStaleOrders extends Command
{
    protected $signature = 'orders:purge-stale';

    protected $description = 'Move stale in-progress POS carts (untouched for over an hour) to hold, or permanently discard them if empty.';

    public function handle(): int
    {
        Order::purgeStaleInProgress();

        $this->info('Stale in-progress orders purged.');

        return self::SUCCESS;
    }
}
