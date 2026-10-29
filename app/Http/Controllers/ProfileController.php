<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ProfileController extends Controller
{
    /** Self-service settings — every logged-in user can change their own
     *  name and password. Email is intentionally locked here (display only)
     *  so users can't lock themselves out of their own account by mistyping it. */
    public function edit()
    {
        return view('profile.edit', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        // H25: role changes belong in the Users section only. The role
        // field used to be accepted here for admins, which meant the sole
        // admin could demote themselves and lock the system out of
        // administration. It is no longer validated or saved here, so a
        // submitted role value (from a stale form or a crafted request)
        // is ignored. The guard below is defence in depth for that crafted
        // case: a sole active admin may not demote themselves from here.
        if ($user->isAdmin() && $user->is_active && $request->filled('role')
            && $request->input('role') !== 'admin'
            && User::where('role', 'admin')->where('is_active', true)
                ->where('id', '!=', $user->id)->count() === 0) {
            return back()->withErrors(['error' => 'You cannot demote the last active admin. Promote another admin first.']);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            // H26: minimum password length raised from 6 to 10.
            'password' => ['nullable', 'string', 'min:10', 'confirmed'],
        ];

        // M17: a new password is only accepted with the current one, so
        // possession of an unattended logged-in session is not enough to
        // take over the account.
        if ($request->filled('password')) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $data = $request->validate($rules);

        $passwordChanged = !empty($data['password']);
        if ($passwordChanged) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        unset($data['password_confirmation']);

        $user->update($data);

        // H20 (this side): clear the forced-change flag only when the
        // password actually changed — saving just a name must not clear
        // it. Direct assignment (not mass assignment) is used on purpose:
        // must_change_password is not in User::$fillable, and this file
        // must work without a model change. Guarded for the period before
        // the migration has run, when the column does not exist yet.
        if ($passwordChanged && Schema::hasColumn('users', 'must_change_password')) {
            $user->must_change_password = false;
            $user->save();
        }

        return back()->with('status', 'Settings updated.');
    }
}
