@extends('layouts.app')
@section('title', $party->name . ' — Cash Ledger')
@section('content')
<div class="flex justify-between items-center mb-1">
    <h1 class="text-2xl font-bold">{{ $party->name }} {{ __('— Cash Ledger') }}</h1>
    <a href="{{ route('cash-management.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back') }}</a>
</div>
<p class="text-gray-600 mb-4">{{ __('Currently owed') }}: <span class="font-bold {{ $party->balance > 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($party->balance, 2) }}</span></p>
@if(!empty($balanceCorrected))
<div class="bg-yellow-50 text-yellow-800 text-sm px-4 py-2 rounded-lg mb-4">{{ __('Balance corrected from') }} {{ number_format($balanceCorrected['old'], 2) }} to {{ number_format($balanceCorrected['new'], 2) }} {{ __('to match the transaction history.') }}</div>
@endif

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h2 class="font-semibold mb-3">{{ __('Record Borrow / Repay') }}</h2>
    <form method="POST" action="{{ route('cash-management.store-transaction', $party) }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="flex gap-2">
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50 text-sm">
                <input type="radio" name="type" value="borrow" checked> {{ __('Borrowed') }}
            </label>
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-green-500 has-[:checked]:bg-green-50 text-sm">
                <input type="radio" name="type" value="repay"> {{ __('Repaid') }}
            </label>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Amount') }}</label>
            <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="border rounded px-3 py-2 w-36">
            @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Date') }}</label>
            <input type="date" name="transaction_date" value="{{ now()->toDateString() }}" required class="border rounded px-3 py-2">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Note (optional)') }}</label>
            <input name="note" placeholder="{{ __('e.g. For shop rent') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <button class="btn btn-solid-green">{{ __('Save') }}</button>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Type') }}</th><th class="p-3">{{ __('Amount') }}</th><th class="p-3">{{ __('Note') }}</th><th class="p-3">{{ __('Recorded By') }}</th><th class="p-3">{{ __('Actions') }}</th></tr></thead>
    <tbody>
    @forelse($transactions as $t)
        <tr class="border-t">
            <td class="p-3">{{ $t->transaction_date->format('Y-m-d') }}</td>
            <td class="p-3">
                @if($t->type === 'borrow')
                    <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">{{ __('Borrowed') }}</span>
                @else
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">{{ __('Repaid') }}</span>
                @endif
            </td>
            <td class="p-3 font-semibold {{ $t->type === 'borrow' ? 'text-red-600' : 'text-green-700' }}">
                {{ $t->type === 'borrow' ? '+' : '-' }}{{ number_format($t->amount, 2) }}
            </td>
            <td class="p-3 text-gray-500">{{ $t->note }}</td>
            <td class="p-3">{{ $t->user->name ?? '—' }}</td>
            <td class="p-3">
                <form method="POST" action="{{ route('cash-management.destroy-transaction', [$party, $t]) }}" class="inline confirm-submit" data-confirm-message="{{ __('Remove this transaction? The balance will be adjusted back.') }}">
                    @csrf @method('DELETE')<button class="btn btn-red">{{ __('Delete') }}</button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="6">{{ __('No transactions yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $transactions->links() }}</div>
@endsection
