@extends('layouts.app')
@section('title', __('Opening Balance'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Opening Balance') }}</h1>
    <a href="{{ route('accounting.journal') }}" class="btn btn-gray">{{ __('Back to Journal') }}</a>
</div>

<p class="text-xs text-gray-400 mb-4">{{ __('Admin only. Use this once, when the accounting feature is first installed, to bring the balances you already had into the journal — for example Dr Cash / Dr Inventory against Cr Capital. The entry must balance: total debits must equal total credits, each line is either a debit or a credit (not both), and empty lines are ignored. This does NOT backfill pre-install history: only the opening balances you enter here will appear in Profit & Loss and the Balance Sheet.') }}</p>

@if($errors->any())
<div class="bg-red-50 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">
    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
</div>
@endif

<div class="bg-white rounded-xl shadow-sm p-5">
<form method="POST" action="{{ route('accounting.opening-balance.store') }}">
    @csrf
    <div class="flex flex-wrap gap-3 mb-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Date') }}</label>
            <input type="date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}" required class="border rounded px-3 py-2">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Description (optional)') }}</label>
            <input name="description" value="{{ old('description') }}" placeholder="{{ __('e.g. Opening balances at install') }}" class="border rounded px-3 py-2 w-full">
        </div>
    </div>

    <table class="w-full text-sm mb-4">
        <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Account') }}</th><th class="p-3">{{ __('Debit') }}</th><th class="p-3">{{ __('Credit') }}</th></tr></thead>
        <tbody>
        @for($i = 0; $i < 6; $i++)
            <tr class="border-t">
                <td class="p-3">
                    <select name="lines[{{ $i }}][account]" class="border rounded px-3 py-2 w-full">
                        <option value="">{{ __('— choose —') }}</option>
                        @foreach($accounts as $a)
                            <option value="{{ $a }}" {{ old("lines.$i.account") === $a ? 'selected' : '' }}>{{ $a }}</option>
                        @endforeach
                    </select>
                </td>
                <td class="p-3"><input type="number" step="0.01" min="0" name="lines[{{ $i }}][debit]" value="{{ old("lines.$i.debit") }}" class="border rounded px-3 py-2 w-full"></td>
                <td class="p-3"><input type="number" step="0.01" min="0" name="lines[{{ $i }}][credit]" value="{{ old("lines.$i.credit") }}" class="border rounded px-3 py-2 w-full"></td>
            </tr>
        @endfor
        </tbody>
    </table>
    <p class="text-xs text-gray-400 mb-4">{{ __('Tip: a typical opening entry is Dr Cash + Dr Inventory (what the business already owns) against Cr Capital + Cr Supplier Payables + Cr Cash Borrowing (what it owes / what was invested). Leave unused rows blank.') }}</p>
    <button class="btn btn-solid-green">{{ __('Post Opening Balance') }}</button>
</form>
</div>
@endsection
