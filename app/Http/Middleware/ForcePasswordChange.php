<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** v3.1.0 (H5) — force a password change before any other page.
 *
 *  If the logged-in user has must_change_password set (seeded admin
 *  via C1, or a database reset via BackupController), every request is
 *  redirected to profile.edit until the password is actually changed.
 *  ProfileController already clears the flag on a real password change
 *  — this middleware only enforces it; it never clears it itself.
 *
 *  Exempt, and only these: profile.edit / profile.update (where the
 *  change happens) and the logout routes (a flagged user must always
 *  be able to log out, including the GET confirm page). The flag read
 *  degrades to false when the column does not exist yet (pre-migration
 *  installs), so this never errors on an old database. Registered in
 *  bootstrap/app.php, appended to the web group after SetLocale, so
 *  the session (and therefore the user) is available to check. */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (bool) ($user->must_change_password ?? false)
            && !$request->routeIs('profile.edit', 'profile.update', 'logout', 'logout.confirm')) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Please change your password before continuing.'], 403);
            }

            return redirect()->route('profile.edit')
                ->with('status', 'Please change your password before continuing.');
        }

        return $next($request);
    }
}
