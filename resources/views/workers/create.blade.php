@extends('layouts.app')
@section('title', __('Add Worker'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Add Worker') }}</h1>
    <a href="{{ route('workers.index') }}" class="btn btn-gray">{{ __('Back') }}</a>
</div>
<div class="bg-white rounded-xl shadow-sm p-6 max-w-2xl">
    <form method="POST" action="{{ route('workers.store') }}">
        @csrf
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Name') }}</label>
            <input name="name" value="{{ old('name') }}" required class="w-full border rounded px-3 py-2">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="mb-4">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Worker Code') }}</label>
            <input name="code" value="{{ old('code') }}" required maxlength="50" placeholder="e.g. W1" class="w-full border rounded px-3 py-2">
            <p class="text-xs text-gray-500 mt-1">{{ __('Typed at billing time to show who made the sale. Must be unique. This is not a login — a worker cannot sign in.') }}</p>
            @error('code') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="mb-4">
            <label class="inline-flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" name="is_active" value="1" checked> {{ __('Active') }}</label>
        </div>
        <button class="btn btn-dark">{{ __('Save Worker') }}</button>
    </form>
</div>
@endsection
