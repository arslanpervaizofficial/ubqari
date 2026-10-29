@extends('layouts.app')
@section('title', __('Profit & Loss'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Profit & Loss') }}</h1>
    <div class="flex gap-2">
        <a href="{{ route('accounting.journal') }}" class="btn btn-gray">{{ __('Journal') }}</a>
        <a href="{{ route('accounting.balance-sheet') }}" class="btn btn-gray">{{ __('Balance Sheet') }}</a>
    </div>
</div>

<p class="text-xs text-gray-400 mb-4">{{ __('From journal entries only (never from orders or expenses directly): Revenue − Sales Returns − COGS − Expenses = Net. Figures cover journal entries from install date onward only — sales, costs or expenses from before this feature was installed have no journal entries and are NOT included in these figures.') }}</p>
{{-- v3.1.0 (H12) honest-coverage note, same list as the journal page, kept short here. --}}
<p class="text-xs text-gray-400 mb-4">{{ __('Coverage note: these figures cover the journal postings listed on the Journal page (originals plus reversals, restores and edit adjustments). They do not yet reflect order or purchase-order corrections that keep the status completed/received, the Inventory/COGS legs of stock returns, supplier returns, stock-count adjustments, manual customer charges, supplier credit/debit notes, capital injections, order-ledger debit rows, or permanent deletes from Trash.') }}</p>
<p class="text-xs text-gray-400 mb-4">{{ __('COGS note: cost is quantity × each product\'s current net purchase price at posting time (the same net cost the margin report uses). There is no historical cost snapshot per sale, so editing a product\'s cost after a sale changes what future postings use, not past entries.') }}</p>

<form method="GET" class="flex gap-2 mb-4">
    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">{{ __('Filter') }}</button>
</form>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Sales Revenue') }}</div>
        <div class="text-2xl font-bold text-green-700">{{ number_format($revenue, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Net credit of the Sales Revenue account') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('COGS') }}</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($cogs, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Net debit of the COGS account') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Expenses') }}</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($expenses, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Net debit of the Expenses account (cash-out expenses only)') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Net Profit / (Loss)') }}</div>
        <div class="text-2xl font-bold {{ $net < 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($net, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Revenue − Sales Returns − COGS − Expenses') }}</div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <tbody>
        <tr class="border-t"><td class="p-3">{{ __('Sales Revenue') }}</td><td class="p-3 text-right font-semibold">{{ number_format($revenue, 2) }}</td></tr>
        <tr class="border-t"><td class="p-3">{{ __('Less: Sales Returns') }}</td><td class="p-3 text-right">({{ number_format($salesReturns, 2) }})</td></tr>
        <tr class="border-t"><td class="p-3">{{ __('Less: Cost of Goods Sold (COGS)') }}</td><td class="p-3 text-right">({{ number_format($cogs, 2) }})</td></tr>
        <tr class="border-t"><td class="p-3">{{ __('Gross Profit') }}</td><td class="p-3 text-right font-semibold">{{ number_format($revenue - $salesReturns - $cogs, 2) }}</td></tr>
        <tr class="border-t"><td class="p-3">{{ __('Less: Expenses') }}</td><td class="p-3 text-right">({{ number_format($expenses, 2) }})</td></tr>
        <tr class="border-t"><td class="p-3 font-bold">{{ __('Net Profit / (Loss)') }}</td><td class="p-3 text-right font-bold {{ $net < 0 ? 'text-red-600' : '' }}">{{ number_format($net, 2) }}</td></tr>
    </tbody>
</table>
</div>
@endsection
