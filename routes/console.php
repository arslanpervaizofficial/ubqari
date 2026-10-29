<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Feature 7: daily automatic backup at 02:00 (app timezone — see
// config/app.php, currently UTC). Writes a full JSON backup to
// storage/app/backups/ and keeps only the last 7 scheduled files.
// This line ONLY runs if the server has the cron entry:
//   * * * * * php artisan schedule:run
// Without that cron line, nothing here is automatic.
Schedule::command('backup:scheduled')->dailyAt('02:00')->timezone('Asia/Karachi');

// v3.1.0 (M15): purge stale in-progress POS carts hourly. Previously
// Order::purgeStaleInProgress() was only called from the report
// controllers, so it ran only when someone opened a report — never
// from POS, the dashboard, or on any schedule. This line ONLY runs if
// the server has the cron entry (same one the backup above needs):
//   * * * * * php artisan schedule:run
// Without that cron line, nothing here is automatic. The method itself
// is unchanged: carts untouched for over an hour become 'hold' if they
// have items, or are permanently discarded if empty (never Trash).
Schedule::command('orders:purge-stale')->hourly();
