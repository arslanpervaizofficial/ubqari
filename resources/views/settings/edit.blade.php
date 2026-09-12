@extends('layouts.app')
@section('title', 'Project Settings')
@section('content')
<h1 class="text-2xl font-bold mb-4">Project Settings</h1>

<form method="POST" action="{{ route('settings.update') }}" class="bg-white p-6 rounded-xl shadow-sm w-full max-w-xl">
    @csrf
    @method('PUT')

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">Project Title (menu / sidebar / browser tab)</label>
        <input name="menu_title" value="{{ old('menu_title', $settings->menu_title) }}" required maxlength="100"
               data-label="Project Title" class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">Shown at the top of the sidebar and in the browser tab title.</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">Print Title (invoice header)</label>
        <input name="print_title" value="{{ old('print_title', $settings->print_title) }}" required maxlength="100"
               data-label="Print Title" class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">Shown at the top of the printed invoice/receipt — can be different from the menu title above.</p>
    </div>

    <button class="btn btn-dark">Save Settings</button>
</form>
@endsection
