<?php

namespace App\Console\Commands;

use App\Models\SyncDeviceToken;
use Illuminate\Console\Command;

/** Lists sync device tokens. Token hashes are deliberately never
 *  printed — this table shows identity and last use only. */
class SyncListTokens extends Command
{
    protected $signature = 'sync:list-tokens';

    protected $description = 'List sync device tokens (hashes are never shown).';

    public function handle(): int
    {
        $tokens = SyncDeviceToken::query()
            ->orderBy('device_id')
            ->get(['id', 'device_id', 'name', 'last_used_at', 'created_at']);

        if ($tokens->isEmpty()) {
            $this->info('No sync device tokens issued yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Device', 'Name', 'Last used', 'Created'],
            $tokens->map(fn ($t) => [
                $t->id,
                $t->device_id,
                $t->name ?? '—',
                $t->last_used_at ? $t->last_used_at->toDateTimeString() : 'never',
                $t->created_at->toDateTimeString(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
