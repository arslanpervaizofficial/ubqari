<?php

namespace App\Console\Commands;

use App\Models\SyncDeviceToken;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Issues one sync device token. The token is printed ONCE, here, and
 *  only its hash is stored — it cannot be recovered afterwards. If
 *  the token is lost, revoke it and issue a new one.
 *
 *  One live token per device_id: issuing while one already exists is
 *  refused, so an admin cannot silently orphan a token the shop PC is
 *  already using. Revoke first, then re-issue. */
class SyncIssueToken extends Command
{
    protected $signature = 'sync:issue-token {--device= : Device id, e.g. shop-pc-01} {--name= : Human-readable device name}';

    protected $description = 'Issue a sync device token (printed once; only its hash is stored).';

    public function handle(): int
    {
        $device = (string) $this->option('device');

        if ($device === '') {
            $this->error('A device id is required: php artisan sync:issue-token --device=shop-pc-01');

            return self::FAILURE;
        }

        if (SyncDeviceToken::query()->where('device_id', $device)->exists()) {
            $this->error("Device '{$device}' already has a token. Revoke it first (sync:revoke-token {$device}), then re-issue.");

            return self::FAILURE;
        }

        $token = Str::random(64);

        SyncDeviceToken::create([
            'device_id' => $device,
            'name' => $this->option('name') ?: null,
            'token_hash' => hash('sha256', $token),
        ]);

        $this->info("Token for device '{$device}' — copy it now, it is shown only once:");
        $this->line($token);
        $this->warn('Paste it into the desktop app settings. It cannot be shown again.');

        return self::SUCCESS;
    }
}
