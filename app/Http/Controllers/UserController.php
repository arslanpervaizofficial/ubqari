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

        // v3.1.0 (L2): never trust the client-supplied extension
        // (a script renamed .php would keep a dangerous suffix). The
        // extension is detected from the file's actual MIME type, and
        // the validation below additionally restricts it to image types.
        $file = $request->file('image');
        $filename = Str::random(20) . '.' . $file->extension();
        $file->move($dir, $filename);

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
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'role' => ['required', 'in:admin,manager,cashier'],
        ]);

        $data['image'] = $this->handleImageUpload($request);
        $data['password'] = Hash::make($data['password']);
        unset($data['password_confirmation']);

        // v3.1.0 (L4): password/role are no longer in User::$fillable,
        // so creation goes through forceFill() — explicit server-side
        // assignment of validated values, never request mass assignment.
        (new User)->forceFill($data)->save();

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    /** H25: count of active, non-trashed admins outside the given ids.
     *  "Active" means role admin AND is_active, matching what can actually
     *  administer the system. Soft-deleted users are excluded by default. */
    private function activeAdminCountExcluding(array $ids = []): int
    {
        $query = User::where('role', 'admin')->where('is_active', true);
        if ($ids) {
            $query->whereNotIn('id', $ids);
        }
        return $query->count();
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
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'role' => ['required', 'in:admin,manager,cashier'],
            'password' => ['nullable', 'string', 'min:10', 'confirmed'],
        ]);

        // H25: never demote the last active admin — there would be no one
        // left who can reach the admin-only routes, and recovery would need
        // direct database access.
        if ($user->role === 'admin' && $user->is_active && $data['role'] !== 'admin'
            && $this->activeAdminCountExcluding([$user->id]) === 0) {
            return back()->withErrors(['error' => 'You cannot demote the last active admin. Promote another admin first.']);
        }

        $data['image'] = $this->handleImageUpload($request, $user);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        unset($data['password_confirmation']);

        // v3.1.0 (L4): see store() — role/password come from validated
        // admin input but are assigned explicitly, not mass-assigned.
        $user->forceFill($data)->save();

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'You cannot delete your own account.']);
        }
        // H25: never delete the last active admin.
        if ($user->role === 'admin' && $user->is_active
            && $this->activeAdminCountExcluding([$user->id]) === 0) {
            return back()->withErrors(['error' => 'You cannot delete the last active admin. Promote another admin first.']);
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
        // H25: if the selection contains every active admin, refuse the
        // whole batch instead of deleting some and stranding the system.
        $adminsInSelection = User::whereIn('id', $ids)->where('role', 'admin')->where('is_active', true)->count();
        if ($adminsInSelection > 0 && $this->activeAdminCountExcluding($ids) === 0) {
            return back()->withErrors(['error' => 'You cannot delete the last active admin. Promote another admin first.']);
        }
        $count = User::whereIn('id', $ids)->delete();
        return back()->with('status', "{$count} user(s) moved to Trash.");
    }

    /** Deletes every user currently listed except your own account. */
    public function destroyAll()
    {
        // H25: same guard as destroySelected — deleting everyone except
        // yourself must not remove the last other active admin when you
        // are not an admin yourself.
        $ids = User::where('id', '!=', auth()->id())->pluck('id')->all();
        $adminsInSelection = User::whereIn('id', $ids)->where('role', 'admin')->where('is_active', true)->count();
        if ($adminsInSelection > 0 && $this->activeAdminCountExcluding($ids) === 0) {
            return back()->withErrors(['error' => 'You cannot delete the last active admin. Promote another admin first.']);
        }
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
        // H25: disabling (not enabling) the last active admin is refused.
        if ($user->is_active && $user->role === 'admin'
            && $this->activeAdminCountExcluding([$user->id]) === 0) {
            return back()->withErrors(['error' => 'You cannot disable the last active admin. Promote another admin first.']);
        }
        $user->forceFill(['is_active' => !$user->is_active])->save();
        $label = $user->is_active ? 'enabled' : 'disabled';
        return back()->with('status', "{$user->name} {$label}.");
    }
}
