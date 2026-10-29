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
