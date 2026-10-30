<?php

namespace App\Console\Commands;

use App\Models\SyncDeviceToken;
use Illuminate\Console\Command;

/** Revokes sync device tokens. Give a device id to revoke every token
 *  for that device, or --id= to revoke one token row. A revoked device
 *  gets a 401 on its next sync and must be re-issued. */
class SyncRevokeToken extends Command
{
    protected $signature = 'sync:revoke-token {device? : Device id to revoke} {--id= : Revoke one token row by id}';

    protected $description = 'Revoke sync device token(s) by device id or token row id.';

    public function handle(): int
    {
        $id = $this->option('id');
        $device = $this->argument('device');

        if ($id !== null && $id !== '') {
            $deleted = SyncDeviceToken::query()->where('id', $id)->delete();
            $this->info($deleted > 0 ? "Revoked token row #{$id}." : "No token row #{$id} found.");

            return $deleted > 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($device === null || $device === '') {
            $this->error('Give a device id or --id=: php artisan sync:revoke-token shop-pc-01');

            return self::FAILURE;
        }

        $deleted = SyncDeviceToken::query()->where('device_id', $device)->delete();
        $this->info($deleted > 0 ? "Revoked {$deleted} token(s) for device '{$device}'." : "No tokens found for device '{$device}'.");

        return $deleted > 0 ? self::SUCCESS : self::FAILURE;
    }
}
