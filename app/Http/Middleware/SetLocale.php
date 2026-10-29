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
 * Registration is the coordinator's job (bootstrap/app.php), where
 * this middleware is APPENDED to the 'web' group (not registered under
 * a 'locale' alias):
 *   $middleware->web(append: [\App\Http\Middleware\SetLocale::class]);
 * Appending it to 'web' is what makes session() available here, since
 * it then runs after StartSession. This file alone does not change any
 * existing middleware wiring.
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
