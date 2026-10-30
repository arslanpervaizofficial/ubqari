<?php

namespace App\Http\Middleware;

use App\Models\SyncDeviceToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Device-token auth for the sync API (SYNC_API_CONTRACT.md: every
 *  request carries `Authorization: Bearer <DEVICE_TOKEN>`).
 *
 *  The token is hashed (sha256) and looked up in sync_device_tokens;
 *  a miss is a 401 in the contract's error shape, and the client must
 *  stop syncing and ask for a new token. On a hit, last_used_at is
 *  refreshed with a plain query-builder update (so the token row's
 *  own updated_at is left alone) and the token row is attached to
 *  the request for controllers that want the device identity.
 *
 *  This is intentionally separate from the web session auth: a shop
 *  PC has no logged-in user session, and a device token must never
 *  grant web-admin access. */
class SyncDeviceTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->unauthorized();
        }

        $deviceToken = SyncDeviceToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($deviceToken === null) {
            return $this->unauthorized();
        }

        SyncDeviceToken::query()
            ->where('id', $deviceToken->id)
            ->update(['last_used_at' => now()]);

        $request->attributes->set('sync_device', $deviceToken);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Invalid or missing device token.',
            ],
        ], 401);
    }
}
