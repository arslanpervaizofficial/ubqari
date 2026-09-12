@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<h1 class="text-2xl font-bold mb-4">Settings</h1>

<form method="POST" action="{{ route('profile.update') }}" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf
    @method('PUT')

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">Name</label>
        <input name="name" value="{{ old('name', $user->name) }}" required data-label="Name" class="w-full border rounded px-3 py-2">
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">Email</label>
        <input value="{{ $user->email }}" disabled class="w-full border rounded px-3 py-2 bg-gray-100 text-gray-500 cursor-not-allowed">
        <p class="text-xs text-gray-400 mt-1">Email can't be changed here — ask an admin if it needs to be updated.</p>
    </div>

    @if($user->isAdmin())
    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">Role</label>
        <select name="role" class="w-full border rounded px-3 py-2">
            <option value="admin" @selected($user->role === 'admin')>Admin</option>
            <option value="manager" @selected($user->role === 'manager')>Manager</option>
            <option value="cashier" @selected($user->role === 'cashier')>Cashier</option>
        </select>
    </div>
    @endif

    <div class="border-t pt-4 mt-2">
        <p class="text-sm font-medium text-gray-700 mb-3">Change Password <span class="text-gray-400 font-normal">(leave blank to keep current password)</span></p>
        <div class="mb-3">
            <label class="block text-sm text-gray-600 mb-1">New Password</label>
            <input type="password" name="password" minlength="6" class="w-full border rounded px-3 py-2">
        </div>
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">Confirm New Password</label>
            <input type="password" name="password_confirmation" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <button class="btn btn-dark">Save Settings</button>
</form>
@endsection
