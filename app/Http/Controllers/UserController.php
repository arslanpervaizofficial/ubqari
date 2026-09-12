<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('name')->paginate(20);
        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.create');
    }

    private function handleImageUpload(Request $request, User $user = null): ?string
    {
        if (!$request->hasFile('image')) {
            return $user->image ?? null;
        }

        $dir = public_path('uploads/users');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($user && $user->image && file_exists($dir . '/' . $user->image)) {
            @unlink($dir . '/' . $user->image);
        }

        $filename = Str::random(20) . '.' . $request->file('image')->getClientOriginalExtension();
        $request->file('image')->move($dir, $filename);

        return $filename;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username', 'alpha_dash'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s]{7,15}$/'],
            'city' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'id_card_number' => ['nullable', 'string', 'regex:/^\d{5}-\d{7}-\d{1}$/'],
            'image' => ['nullable', 'image', 'max:2048'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', 'in:admin,manager,cashier'],
        ]);

        $data['image'] = $this->handleImageUpload($request);
        $data['password'] = Hash::make($data['password']);
        unset($data['password_confirmation']);

        User::create($data);

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username,' . $user->id, 'alpha_dash'],
            'email' => ['required', 'email', 'unique:users,email,' . $user->id],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s]{7,15}$/'],
            'city' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'id_card_number' => ['nullable', 'string', 'regex:/^\d{5}-\d{7}-\d{1}$/'],
            'image' => ['nullable', 'image', 'max:2048'],
            'role' => ['required', 'in:admin,manager,cashier'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ]);

        $data['image'] = $this->handleImageUpload($request, $user);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        unset($data['password_confirmation']);

        $user->update($data);

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'You cannot delete your own account.']);
        }
        $user->delete();
        return back()->with('status', 'User moved to Trash.');
    }

    /** Bulk-delete just the checked rows — moves each to Trash (soft delete).
     *  Your own account is silently skipped if it's in the selection, same
     *  protection as the single-delete button. */
    public function destroySelected(Request $request)
    {
        $ids = array_diff((array) $request->input('ids', []), [auth()->id()]);
        $count = User::whereIn('id', $ids)->delete();
        return back()->with('status', "{$count} user(s) moved to Trash.");
    }

    /** Deletes every user currently listed except your own account. */
    public function destroyAll()
    {
        $count = User::where('id', '!=', auth()->id())->delete();
        return back()->with('status', "{$count} user(s) moved to Trash.");
    }

    /** Disable instead of delete — keeps their name attached to past billing/
     *  ledger records intact while blocking them from logging in. */
    public function toggleActive(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'You cannot disable your own account.']);
        }
        $user->update(['is_active' => !$user->is_active]);
        $label = $user->is_active ? 'enabled' : 'disabled';
        return back()->with('status', "{$user->name} {$label}.");
    }
}
