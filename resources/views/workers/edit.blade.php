@extends('layouts.app')
@section('title', __('Edit Worker'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Edit Worker') }}</h1>
    <a href="{{ route('workers.index') }}" class="btn btn-gray">{{ __('Back') }}</a>
</div>
<div class="bg-white rounded-xl shadow-sm p-6 max-w-2xl">
    <form method="POST" action="{{ route('workers.update', $worker) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Name') }}</label>
            <input name="name" value="{{ old('name', $worker->name) }}" required class="w-full border rounded px-3 py-2">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Worker Code') }}</label>
            <input name="code" value="{{ old('code', $worker->code) }}" required maxlength="50" placeholder="e.g. W1" class="w-full border rounded px-3 py-2">
            <p class="text-xs text-gray-500 mt-1">{{ __('Typed at billing time to show who made the sale. Must be unique. This is not a login — a worker cannot sign in.') }}</p>
            @error('code') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{ __('Phone Number') }</label>
            <input name="phone" value="{{ old('phone', $worker->phone) }}" required placeholder="03001234567" class="w-full border rounded px-3 py-2">
            <p class="text-xs text-gray-500 mt-1">{ __('Digits only after saving; dashes and spaces are removed automatically. 7 to 15 digits.') }</p>
            @error('phone') <p class="text-red-600 text-xs mt-1">{ $message }</p> @enderror
        </div>
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{ __('CNIC') }</label>
            <input name="cnic" value="{{ old('cnic', $worker->cnic) }}" required placeholder="35202-1234567-1" class="w-full border rounded px-3 py-2">
            <p class="text-xs text-gray-500 mt-1">{ __('Format: 5 digits, dash, 7 digits, dash, 1 digit. Each CNIC can belong to only one worker.') }</p>
            @error('cnic') <p class="text-red-600 text-xs mt-1">{ $message }</p> @enderror
        </div>
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{ __('Email (optional)') }</label>
            <input type="email" name="email" value="{{ old('email', $worker->email) }}" class="w-full border rounded px-3 py-2">
            @error('email') <p class="text-red-600 text-xs mt-1">{ $message }</p> @enderror
        </div>
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{ __('Photo (optional)') }</label>
            <img id="worker-image-preview" src="{{ $worker->image_url }}" class="w-20 h-20 rounded-full object-cover mb-2">
            <p class="text-xs text-gray-500 mt-1 mb-2">{{ __('Leave blank to keep the current photo.') }}</p>
            <input type="file" name="image" accept="image/*" onchange="document.getElementById('worker-image-preview').src = URL.createObjectURL(this.files[0])" class="text-sm">
            <p class="text-xs text-gray-500 mt-1">{ __('JPG, PNG or WebP, max 2 MB.') }</p>
            @error('image') <p class="text-red-600 text-xs mt-1">{ $message }</p> @enderror
        </div>
        <div class="mb-4">
            <label class="inline-flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $worker->is_active))> {{ __('Active') }}</label>
        </div>
        <button class="btn btn-dark">{{ __('Save Worker') }}</button>
    </form>
</div>
@endsection
