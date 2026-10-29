<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
        // v3.0 FEATURE 9: payment gateways post callbacks server-to-server
        // with no shop session/CSRF token — exempt only these callback
        // URLs (the initiate form stays CSRF-protected). No withCommands()
        // line is needed for the scheduled-backup command: Laravel 12
        // auto-discovers app/Console/Commands (verified in framework
        // ApplicationBuilder code).
        $middleware->validateCsrfTokens(except: [
            'online-payments/callback/*',
        ]);
        // v3.0 FEATURE 8: locale from session (default English). Appended
        // to the web group so it runs after StartSession, which the
        // session read depends on.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            // v3.1.0 (H5): forced password change. Appended after
            // SetLocale, same pattern: it runs after StartSession, so
            // the logged-in user is available to check.
            \App\Http\Middleware\ForcePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Session/CSRF token expired (e.g. the tab sat idle past
        // SESSION_LIFETIME) → by default Laravel shows a bare "419 | Page
        // Expired" error page for a normal form submit. That's confusing
        // for a cashier who just wants to log back in and carry on, so we
        // send them to the login screen instead, with a plain-language
        // explanation. AJAX/fetch calls (Billing's "Load Order", live
        // search, etc.) keep getting a small JSON 419 instead of an HTML
        // redirect, since following a redirect silently would just hand
        // back a login page as if it were JSON.
        //
        // Laravel's handler converts TokenMismatchException into a plain
        // HttpException(419) internally before any render() callback typed
        // to the original exception class would ever see it, so this has
        // to be a respond() callback that inspects the final status code
        // instead of a render() callback typed to TokenMismatchException.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($response->getStatusCode() === 419 && !$request->expectsJson()) {
                return redirect()->route('login')->with('status', 'Your session had expired — please log in again to continue.');
            }

            return $response;
        });
    })->create();
