@extends('layouts.app')
@section('title', 'Add User')
@section('content')
<h1 class="text-2xl font-bold mb-4">Add Staff User</h1>
<form method="POST" action="{{ route('users.store') }}" enctype="multipart/form-data" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf
    <div class="flex items-center gap-4 mb-5">
        <img id="image-preview" src="https://ui-avatars.com/api/?name=New&background=1e293b&color=fff&size=128" class="w-20 h-20 rounded-full object-cover border">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Photo (optional)</label>
            <input type="file" name="image" accept="image/*" onchange="document.getElementById('image-preview').src = URL.createObjectURL(this.files[0])" class="text-sm">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Full Name</label>
            <input name="name" value="{{ old('name') }}" required data-label="Name" class="w-full border rounded px-3 py-2">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Username</label>
            <input name="username" value="{{ old('username') }}" required data-label="Username" class="w-full border rounded px-3 py-2">
            @error('username') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required data-label="Email" class="w-full border rounded px-3 py-2">
            @error('email') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Phone</label>
            <input name="phone" value="{{ old('phone') }}" pattern="^[0-9+\-\s]{7,15}$"
                   data-pattern-message="Enter a valid phone number (digits, spaces, + or - only, 7–15 characters)."
                   class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">City</label>
            <input name="city" value="{{ old('city') }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">ID Card Number (CNIC)</label>
            <input name="id_card_number" value="{{ old('id_card_number') }}" placeholder="12345-1234567-1" maxlength="15"
                   pattern="^\d{5}-\d{7}-\d{1}$" data-pattern-message="CNIC must be in the format 12345-1234567-1."
                   class="w-full border rounded px-3 py-2">
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm text-gray-600 mb-1">Address</label>
            <input name="address" value="{{ old('address') }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Role</label>
            <select name="role" required data-label="Role" class="w-full border rounded px-3 py-2">
                <option value="admin" @selected(old('role')==='admin')>Admin (full access)</option>
                <option value="manager" @selected(old('role')==='manager')>Manager (all except users)</option>
                <option value="cashier" @selected(old('role', 'cashier')==='cashier')>Cashier (billing only)</option>
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Password</label>
            <input type="password" name="password" required minlength="6" data-label="Password" class="w-full border rounded px-3 py-2">
            @error('password') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Confirm Password</label>
            <input type="password" name="password_confirmation" required data-label="Confirm Password" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="mt-6">
        <button class="btn btn-dark">Save User</button>
    </div>
</form>
@endsection
