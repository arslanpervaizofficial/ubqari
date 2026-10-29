<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature 8 — locale switching.
 *
 * Reads the locale from the session (set by the locale.switch route),
 * allows only 'en' and 'ur', and applies it for the current request.
 * Anything else falls back to 'en' and is never passed to setLocale().
 *
 * Registration is the coordinator's job (bootstrap/app.php):
 *   $middleware->alias(['locale' => \App\Http\Middleware\SetLocale::class]);
 * and the alias must be added to the 'web' middleware group (or applied
 * to the authenticated route group) so session() is available here.
 * This file alone does not change any existing middleware wiring.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale', 'en');

        if (! in_array($locale, ['en', 'ur'], true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
