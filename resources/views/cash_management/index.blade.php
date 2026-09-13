@extends('layouts.app')
@section('title', 'Cash Management')
@section('content')
<h1 class="text-2xl font-bold mb-1">Cash Management</h1>
<p class="text-gray-500 text-sm mb-4">A personal record of cash borrowed from (and repaid to) people — separate from the rest of the system, not used anywhere else in the app.</p>

<div class="bg-white p-5 rounded-xl shadow-sm mb-6 max-w-xs">
    <div class="text-gray-500 text-sm">Total Currently Owed</div>
    <div class="text-2xl font-bold {{ $totalOwed > 0 ? 'text-red-600' : '' }}">{{ number_format($totalOwed, 2) }}</div>
</div>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6 max-w-lg">
    <h2 class="font-semibold mb-3">Add a Person / Party</h2>
    <form method="POST" action="{{ route('cash-management.store-party') }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label class="block text-sm text-gray-600 mb-1">Name</label>
            <input name="name" required data-label="Name" class="border rounded px-3 py-2 w-48">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm text-gray-600 mb-1">Note (optional)</label>
            <input name="note" placeholder="e.g. Uncle, shop next door" class="border rounded px-3 py-2 w-full">
        </div>
        <button class="btn btn-solid-green">Add</button>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">Name</th><th class="p-3">Note</th><th class="p-3">Currently Owed</th><th class="p-3">Actions</th></tr></thead>
    <tbody>
    @forelse($parties as $p)
        <tr class="border-t">
            <td class="p-3"><a href="{{ route('cash-management.show', $p) }}" class="font-medium text-blue-600 hover:underline">{{ $p->name }}</a></td>
            <td class="p-3 text-gray-500">{{ $p->note }}</td>
            <td class="p-3 font-semibold {{ $p->balance > 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($p->balance, 2) }}</td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <a href="{{ route('cash-management.show', $p) }}" class="btn btn-gray">View Ledger</a>
                @if(auth()->user()->role === 'admin')
                <form method="POST" action="{{ route('cash-management.destroy-party', $p) }}" class="inline confirm-submit" data-confirm-message="Delete {{ $p->name }} and their entire transaction history? This cannot be undone.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="4">No one added yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
