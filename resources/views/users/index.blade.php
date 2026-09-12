@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Staff / Users</h1>
    <a href="{{ route('users.create') }}" class="btn btn-dark">+ Add User</a>
</div>
<div data-ajax-list="users">
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="users" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="users"
            data-action-url="{{ route('users.destroy-selected') }}"
            data-confirm-message="Move the selected users to Trash?">
        Delete Selected
    </button>
    <button type="button" class="btn btn-red" data-bulk-all-submit
            data-action-url="{{ route('users.destroy-all') }}"
            data-confirm-message="Move ALL users on this list to Trash? (Your own account is always kept.)">
        Delete All
    </button>
</div>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3"><input type="checkbox" data-bulk-select-all="users"></th>
        <th class="p-3"></th><th class="p-3">Name</th><th class="p-3">Username</th><th class="p-3">Email</th><th class="p-3">Role</th><th class="p-3">Status</th><th class="p-3">Actions</th>
    </tr></thead>
    <tbody>
    @foreach($users as $u)
        <tr class="border-t {{ !$u->is_active ? 'opacity-60' : '' }}">
            <td class="p-3">@if($u->id !== auth()->id())<input type="checkbox" data-bulk-item="users" value="{{ $u->id }}">@endif</td>
            <td class="p-3"><img src="{{ $u->image_url }}" class="w-9 h-9 rounded-full object-cover"></td>
            <td class="p-3 font-medium">{{ $u->name }}</td>
            <td class="p-3">{{ $u->username }}</td>
            <td class="p-3">{{ $u->email }}</td>
            <td class="p-3"><span class="px-2 py-1 bg-gray-100 rounded text-xs">{{ $u->role }}</span></td>
            <td class="p-3">
                <span class="px-2 py-1 rounded text-xs {{ $u->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $u->is_active ? 'Active' : 'Disabled' }}</span>
            </td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <a href="{{ route('users.edit', $u) }}" class="btn btn-blue">Edit</a>
                @if($u->id !== auth()->id())
                <form method="POST" action="{{ route('users.toggle-active', $u) }}" class="inline">
                    @csrf
                    <button class="btn {{ $u->is_active ? 'btn-yellow' : 'btn-solid-green' }}">{{ $u->is_active ? 'Disable' : 'Enable' }}</button>
                </form>
                <form method="POST" action="{{ route('users.destroy', $u) }}" class="inline confirm-submit" data-confirm-message="Move this user to Trash? You can restore them anytime from Trash.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
</div>
@endsection
