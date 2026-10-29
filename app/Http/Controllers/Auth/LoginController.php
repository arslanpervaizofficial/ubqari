<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
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
            if (Auth::user()->isCashier()) {
                return redirect()->route('pos.index');
            }
            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
