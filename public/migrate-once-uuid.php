<?php

// One-time migration runner for the 2026-11-03 client_uuid backfill.
// Key-gated, runs ONLY this one migration, then deletes itself.
//
// Install: upload this file to the website's public folder, then open
//   https://ubqari.pk/migrate-once-uuid.php?key=...
// with the key from the $key line below (read it from THIS file on
// your own computer, never send it in chat).
// Expected output: "Migrated: 2026_11_03_000001_backfill_client_uuid"
// (or "Nothing to migrate" if it already ran). If the file is still
// visible in File Manager afterwards, delete it by hand.

$key = '49bdc9beefc8d5d8004f944d3fbdac8b';

if (!isset($_GET['key']) || !hash_equals($key, (string) $_GET['key'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $exit = Illuminate\Support\Facades\Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_11_03_000001_backfill_client_uuid.php',
        '--force' => true,
    ]);
    echo 'Migrate exit: '.$exit."\n";
    echo Illuminate\Support\Facades\Artisan::output();
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage();
}

@unlink(__FILE__);
echo "\nFile deleted.";
