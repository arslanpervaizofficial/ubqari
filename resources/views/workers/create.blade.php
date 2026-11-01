@extends('layouts.app')
@section('title', __('Add Worker'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Add Worker') }}</h1>
    <a href="{{ route('workers.index') }}" class="btn btn-gray">{{ __('Back') }}</a>
</div>
<form method="POST" action="{{ route('workers.store') }}" enctype="multipart/form-data" class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf
    <div class="flex items-center gap-4 mb-5">
        <img id="worker-image-preview" src="https://ui-avatars.com/api/?name=New&background=1e293b&color=fff&size=128" class="w-20 h-20 rounded-full object-cover border">
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Photo (optional)') }}</label>
            <input type="file" name="image" accept="image/*" onchange="document.getElementById('worker-image-preview').src = URL.createObjectURL(this.files[0])" class="text-sm">
            <p class="text-xs text-gray-500 mt-1">{{ __('JPG, PNG or WebP, max 2 MB.') }}</p>
            @error('image') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Name') }}</label>
            <input name="name" value="{{ old('name') }}" required class="w-full border rounded px-3 py-2">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Worker Code') }}</label>
            <input name="code" value="{{ old('code') }}" required maxlength="50" placeholder="e.g. W1" class="w-full border rounded px-3 py-2">
            <p class="text-xs text-gray-500 mt-1">{{ __('Typed at billing time to show who made the sale. Must be unique. This is not a login — a worker cannot sign in.') }}</p>
            @error('code') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Phone Number') }}</label>
            <input name="phone" value="{{ old('phone') }}" required placeholder="03001234567" class="w-full border rounded px-3 py-2">
            <p class="text-xs text-gray-500 mt-1">{{ __('Digits only after saving; dashes and spaces are removed automatically. 7 to 15 digits.') }}</p>
            @error('phone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('CNIC') }}</label>
            <input name="cnic" value="{{ old('cnic') }}" required placeholder="35202-1234567-1" class="w-full border rounded px-3 py-2">
            <p class="text-xs text-gray-500 mt-1">{{ __('Format: 5 digits, dash, 7 digits, dash, 1 digit. Each CNIC can belong to only one worker.') }}</p>
            @error('cnic') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Email (optional)') }}</label>
            <input type="email" name="email" value="{{ old('email') }}" class="w-full border rounded px-3 py-2">
            @error('email') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-end pb-2">
            <label class="inline-flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" name="is_active" value="1" checked> {{ __('Active') }}</label>
        </div>
    </div>

    <div class="mt-6">
        <button class="btn btn-dark">{{ __('Save Worker') }}</button>
    </div>
</form>
@endsection
