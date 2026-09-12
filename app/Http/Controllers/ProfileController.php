<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ];

        // Only an admin gets a role field here (and only ever changes their
        // own role, which this page is scoped to) — everyone else's role is
        // still managed from the Users section.
        if ($user->isAdmin()) {
            $rules['role'] = ['required', 'in:admin,manager,cashier'];
        }

        $data = $request->validate($rules);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        unset($data['password_confirmation']);

        $user->update($data);

        return back()->with('status', 'Settings updated.');
    }
}
