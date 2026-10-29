@extends('layouts.app')
@section('title', __('Balance Sheet'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Balance Sheet') }}</h1>
    <div class="flex gap-2">
        <a href="{{ route('accounting.journal') }}" class="btn btn-gray">{{ __('Journal') }}</a>
        <a href="{{ route('accounting.profit-loss') }}" class="btn btn-gray">{{ __('Profit & Loss') }}</a>
    </div>
</div>

<p class="text-xs text-gray-400 mb-4">{{ __('From journal entries only. Assets = Cash + Bank + Inventory + Receivables (net debits). Liabilities = Supplier Payables + Cash Borrowing (net credits). Equity = Capital (net credit) + retained profit/loss (Revenue − COGS − Expenses, same scope). Figures cover journal entries from install date onward only — balances from before this feature was installed are NOT included unless brought in through an opening-balance entry (admin).') }}</p>

<form method="GET" class="flex gap-2 mb-4">
    <label class="text-sm text-gray-600 flex items-center">{{ __('As of (optional)') }}</label>
    <input type="date" name="as_of" value="{{ $asOf }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">{{ __('Filter') }}</button>
    <a href="{{ route('accounting.balance-sheet') }}" class="btn btn-gray">{{ __('All time') }}</a>
</form>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Assets') }}</th><th class="p-3 text-right">{{ __('Amount') }}</th></tr></thead>
        <tbody>
        @foreach($assets as $name => $amount)
            <tr class="border-t"><td class="p-3">{{ $name }}</td><td class="p-3 text-right">{{ number_format($amount, 2) }}</td></tr>
        @endforeach
        <tr class="border-t"><td class="p-3 font-bold">{{ __('Total Assets') }}</td><td class="p-3 text-right font-bold">{{ number_format($totalAssets, 2) }}</td></tr>
        </tbody>
    </table>
    </div>
    <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Liabilities & Equity') }}</th><th class="p-3 text-right">{{ __('Amount') }}</th></tr></thead>
        <tbody>
        @foreach($liabilities as $name => $amount)
            <tr class="border-t"><td class="p-3">{{ $name }}</td><td class="p-3 text-right">{{ number_format($amount, 2) }}</td></tr>
        @endforeach
        <tr class="border-t"><td class="p-3 font-bold">{{ __('Total Liabilities') }}</td><td class="p-3 text-right font-bold">{{ number_format($totalLiabilities, 2) }}</td></tr>
        <tr class="border-t"><td class="p-3">{{ __('Capital') }}</td><td class="p-3 text-right">{{ number_format($capital, 2) }}</td></tr>
        <tr class="border-t"><td class="p-3">{{ __('Retained Profit / (Loss)') }}</td><td class="p-3 text-right">{{ number_format($retained, 2) }}</td></tr>
        <tr class="border-t"><td class="p-3 font-bold">{{ __('Total Equity') }}</td><td class="p-3 text-right font-bold">{{ number_format($totalEquity, 2) }}</td></tr>
        <tr class="border-t"><td class="p-3 font-bold">{{ __('Total Liabilities + Equity') }}</td><td class="p-3 text-right font-bold">{{ number_format($totalLiabilitiesEquity, 2) }}</td></tr>
        </tbody>
    </table>
    </div>
</div>

<div class="bg-white p-5 rounded-xl shadow-sm">
    <div class="flex justify-between">
        <span class="font-semibold">{{ __('Difference (Assets − Liabilities & Equity)') }}</span>
        <span class="font-bold {{ abs($difference) > 0.004 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($difference, 2) }}</span>
    </div>
    @if(abs($difference) <= 0.004)
        <p class="text-xs text-gray-400 mt-1">{{ __('Balanced: the books reconcile. Every posted entry balances, so this should always be zero.') }}</p>
    @else
        <p class="text-xs text-red-600 mt-1">{{ __('Out of balance: check the journal for an unbalanced or hand-edited entry. Automatic entries cannot cause this (the service refuses unbalanced postings), so a non-zero difference points to data changed outside the service.') }}</p>
    @endif
</div>
@endsection
