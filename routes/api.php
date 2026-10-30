<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

/** Sync API (SYNC_API_CONTRACT.md). This file is loaded as the
 *  app's api routes file (see bootstrap/app.php), so Laravel
 *  applies the /api prefix and the api middleware group to it —
 *  the paths below are therefore served at /api/sync/*.
 *
 *  Auth is the sync.token device-token middleware, NOT a user
 *  session: a shop PC has no logged-in user.
 *
 *  Throttle: an explicit 300 requests/minute on this group. The
 *  base app defines no additional api-group throttle, so 300/min is
 *  the only confirmed ceiling (whether the framework adds another
 *  layer is UNVERIFIED — confirm on staging with route:list and a
 *  burst test). A 500-change sync is 5 push requests plus a handful
 *  of pull pages at the desktop's 100-per-request batching, far
 *  under either ceiling. */
Route::middleware(['sync.token', 'throttle:300,1'])->prefix('sync')->group(function () {
    Route::post('/push', [SyncController::class, 'push']);
    Route::get('/pull', [SyncController::class, 'pull']);
    Route::get('/ping', [SyncController::class, 'ping']);
});
