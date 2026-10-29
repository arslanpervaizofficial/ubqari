@extends('layouts.app')
@section('title', __('Cash Management'))
@section('content')
<h1 class="text-2xl font-bold mb-1">{{ __('Cash Management') }}</h1>
<p class="text-gray-500 text-sm mb-4">{{ __('The single place to inject capital into the business and to record money owed to people — the') }} <a href="{{ route('reports.capital') }}" class="underline">{{ __('Capital Report') }}</a> {{ __('only reports these figures, it doesn\'t manage them.') }}</p>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6 max-w-2xl">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Capital Injected') }}</div>
        <div class="text-2xl font-bold text-green-700">{{ number_format($cashInjected, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('All-time Cash In — feeds Total/Remaining Investment on the Capital Report') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Currently Owed') }}</div>
        <div class="text-2xl font-bold {{ $totalOwed > 0 ? 'text-red-600' : '' }}">{{ number_format($totalOwed, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Liabilities — now subtracted in Net Capital on the Capital Report') }}</div>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5">
        <h2 class="font-semibold mb-3">+ {{ __('Add Capital') }}</h2>
        <p class="text-xs text-gray-400 mb-3">{{ __('Injects fresh money into the business (e.g. owner\'s personal investment) — counts toward Total Investment and Remaining Investment on the Capital Report. This is recorded as a "Cash In" entry, separate from actual expenses.') }}</p>
        <form method="POST" action="{{ route('expenses.store') }}" novalidate class="flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="type" value="cash_in">
            {{-- Marks this as the one legitimate Cash In entry point: ExpenseController rejects cash_in without this marker (the Expenses form no longer offers Cash In at all). --}}
            <input type="hidden" name="source" value="cash_management">
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Amount') }}</label>
                <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="border rounded px-3 py-2 w-32">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Date') }}</label>
                <input type="date" name="expense_date" value="{{ now()->toDateString() }}" required class="border rounded px-3 py-2">
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="block text-sm text-gray-600 mb-1">{{ __('Note (optional)') }}</label>
                <input name="note" placeholder="e.g. Owner's personal investment" class="border rounded px-3 py-2 w-full">
            </div>
            <button class="btn btn-solid-green">{{ __('Add Capital') }}</button>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-5">
        <h2 class="font-semibold mb-3">+ {{ __('Add Liability') }}</h2>
        <p class="text-xs text-gray-400 mb-3">{{ __('Records money owed to a person — same as adding a "Borrow" entry from a party\'s ledger below, just in one step. Pick an existing person, or type a new name to add them.') }}</p>
        <form method="POST" action="{{ route('cash-management.quick-add') }}" novalidate class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Person') }}</label>
                <select name="party_id" class="border rounded px-3 py-2 w-40">
                    <option value="">{{ __('— New person —') }}</option>
                    @foreach($parties as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('New name (if new)') }}</label>
                <input name="party_name" placeholder="{{ __('e.g. Uncle') }}" class="border rounded px-3 py-2 w-32">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Amount') }}</label>
                <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="border rounded px-3 py-2 w-28">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Date') }}</label>
                <input type="date" name="transaction_date" value="{{ now()->toDateString() }}" required class="border rounded px-3 py-2">
            </div>
            <button class="btn btn-solid-yellow">{{ __('Add Liability') }}</button>
        </form>
        @error('party') <p class="text-red-600 text-xs mt-2">{{ $message }}</p> @enderror
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6 max-w-lg">
    <h2 class="font-semibold mb-3">{{ __('Add a Person / Party') }}</h2>
    <form method="POST" action="{{ route('cash-management.store-party') }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Name') }}</label>
            <input name="name" required data-label="Name" class="border rounded px-3 py-2 w-48">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Note (optional)') }}</label>
            <input name="note" placeholder="{{ __('e.g. Uncle, shop next door') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <button class="btn btn-solid-green">{{ __('Add') }}</button>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Name') }}</th><th class="p-3">{{ __('Note') }}</th><th class="p-3">{{ __('Currently Owed') }}</th><th class="p-3">{{ __('Actions') }}</th></tr></thead>
    <tbody>
    @forelse($parties as $p)
        <tr class="border-t">
            <td class="p-3"><a href="{{ route('cash-management.show', $p) }}" class="font-medium text-blue-600 hover:underline">{{ $p->name }}</a></td>
            <td class="p-3 text-gray-500">{{ $p->note }}</td>
            <td class="p-3 font-semibold {{ $p->balance > 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($p->balance, 2) }}</td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <a href="{{ route('cash-management.show', $p) }}" class="btn btn-gray">{{ __('View Ledger') }}</a>
                @if(auth()->user()->role === 'admin')
                <form method="POST" action="{{ route('cash-management.destroy-party', $p) }}" class="inline confirm-submit" data-confirm-message="Move {{ $p->name }} to Trash? Their transaction history will be kept.">
                    @csrf @method('DELETE')<button class="btn btn-red">{{ __('Delete') }}</button>
                </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="4">{{ __('No one added yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
