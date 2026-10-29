<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // v3.1.0 (L3): per-account lockout, on top of the per-IP route
        // throttle (throttle:5,1 in routes/web.php). Keyed on the
        // lowercased email, 5 attempts per minute, cleared on a
        // successful login — so IP-hopping against one account no
        // longer bypasses the limit. No migration needed.
        $throttleKey = 'login:' . strtolower($credentials['email']);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->withErrors(['email' => 'Too many login attempts for this account. Please try again in a minute.'])->onlyInput('email');
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            if (!Auth::user()->is_active) {
                Auth::logout();
                return back()->withErrors(['email' => 'This account has been disabled. Contact your admin.'])->onlyInput('email');
            }
            $request->session()->regenerate();

            // H20 (this side): an account created by a database reset must
            // change its password before doing anything else. The flag is
            // set by the reset flow and cleared by ProfileController when
            // the password is changed. Before the migration has run the
            // column does not exist and the attribute is simply null, so
            // this degrades to "no forced change" instead of erroring.
            if ((bool) (Auth::user()->must_change_password ?? false)) {
                return redirect()->route('profile.edit')
                    ->with('status', 'Please change your password before continuing.');
            }

            // The dashboard is now admin/manager-only (H28), so a cashier
            // logging in goes to the register instead of a 403 page.
            // v2.2 (R10): honour the bookmarked/intended page instead
            // of always dropping the cashier on the blank POS screen —
            // except /dashboard itself, which would just 403 (plain
            // redirect()->intended() would NOT fall back for that case,
            // it would send the cashier straight to the 403).
            if (Auth::user()->isCashier()) {
                $intended = session()->get('url.intended');
                if ($intended && !str_contains($intended, 'dashboard')) {
                    session()->forget('url.intended');
                    return redirect()->to($intended);
                }
                session()->forget('url.intended');
                return redirect()->route('pos.index');
            }
            return redirect()->intended(route('dashboard'));
        }

        RateLimiter::hit($throttleKey, 60);

        return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
    }

    /** v3.1.0 (L1): GET /logout renders this confirm page instead of
     *  logging out directly. The page's form POSTs to route('logout'). */
    public function showLogoutConfirm()
    {
        return view('auth.logout-confirm');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
