@extends('layouts.app')
@section('title', __('Journal'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Journal') }}</h1>
    <div class="flex gap-2">
        <a href="{{ route('accounting.profit-loss') }}" class="btn btn-gray">{{ __('Profit & Loss') }}</a>
        <a href="{{ route('accounting.balance-sheet') }}" class="btn btn-gray">{{ __('Balance Sheet') }}</a>
        @if(auth()->user()->role === 'admin')
            <a href="{{ route('accounting.opening-balance') }}" class="btn btn-solid-green">{{ __('Opening Balance') }}</a>
        @endif
    </div>
</div>

<p class="text-xs text-gray-400 mb-4">{{ __('Every entry below is double-entry: its debit lines always equal its credit lines. Entries are posted automatically when a sale completes, a purchase order is received, an expense is paid, a supplier or customer payment is recorded, or cash is borrowed/repaid. Figures cover journal entries from install date onward only — business done before this feature was installed has no journal entries and does not appear here.') }}</p>

{{-- v3.1.0 (H12) honest-coverage note: the journal shows original postings plus reversal, restore and edit-delta entries, but it does NOT yet reflect every edit type. Not journalled: order or purchase-order item/total corrections that keep the status completed/received; the Inventory/COGS legs of stock returns (only the linked customer-credit leg posts, as Sales Returns); to-supplier returns; stock-count adjustments; manual customer charges (debit) and supplier credit/debit notes; expense capital injections (cash_in); order-ledger debit rows; edits or deletes of order-ledger payment rows; permanent (force) deletes from Trash; and a second delete/restore cycle of the same record (only the first cycle is journalled). --}}
<p class="text-xs text-gray-400 mb-4">{{ __('Coverage note: this journal shows original postings plus reversal, restore and edit-adjustment entries, but it does not yet reflect every edit type — order or purchase-order corrections that keep the status completed/received, the Inventory/COGS legs of stock returns, supplier returns, stock-count adjustments, manual customer charges, supplier credit/debit notes, capital injections, order-ledger debit rows, edits or deletes of order-ledger payments, permanent deletes from Trash, and repeat delete/restore cycles are not journalled. Treat P&L and Balance Sheet figures as covering the posted events above, not every change in the system.') }}</p>

{{-- v3.1.0 (M12) failure note: a posting that fails is written to the server log with its source, not shown as a banner here, because observers must not crash the business action they follow. --}}
<p class="text-xs text-gray-400 mb-4">{{ __('Posting failures are recorded in the server log with the entry they belong to (they do not appear here as a banner). If a recent sale, payment or expense has no journal entry below, check the server log for its source before re-entering it by hand — a duplicate posting is refused automatically.') }}</p>

<form method="GET" class="flex flex-wrap gap-2 mb-4">
    <select name="account" class="border rounded px-3 py-2">
        <option value="">{{ __('All accounts') }}</option>
        @foreach($accounts as $a)
            <option value="{{ $a }}" {{ $account === $a ? 'selected' : '' }}>{{ $a }}</option>
        @endforeach
    </select>
    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">{{ __('Filter') }}</button>
    <a href="{{ route('accounting.journal') }}" class="btn btn-gray">{{ __('Clear') }}</a>
</form>

@forelse($entries as $entry)
<div class="bg-white rounded-xl shadow-sm p-5 mb-4">
    <div class="flex flex-wrap justify-between gap-2 mb-3">
        <div>
            <span class="font-semibold">{{ $entry->entry_date->format('Y-m-d') }}</span>
            @if($entry->reference)<span class="text-gray-500"> &middot; {{ $entry->reference }}</span>@endif
            @if($entry->description)<div class="text-sm text-gray-500">{{ $entry->description }}</div>@endif
            <div class="text-xs text-gray-400 mt-1">Source: {{ $entry->source_type ?? 'manual' }}@if($entry->source_id) #{{ $entry->source_id }}@endif &middot; Posted by {{ $entry->user->name ?? '—' }}</div>
        </div>
        <div class="text-sm text-right">
            <div>{{ __('Dr') }} <span class="font-semibold">{{ number_format($entry->totalDebit(), 2) }}</span></div>
            <div>{{ __('Cr') }} <span class="font-semibold">{{ number_format($entry->totalCredit(), 2) }}</span></div>
            @if(!$entry->isBalanced())<div class="text-red-600 font-semibold text-xs">{{ __('UNBALANCED — needs attention') }}</div>@endif
        </div>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="p-2">{{ __('Account') }}</th><th class="p-2 text-right">{{ __('Debit') }}</th><th class="p-2 text-right">{{ __('Credit') }}</th></tr></thead>
        <tbody>
        @foreach($entry->lines as $line)
            <tr class="border-t">
                <td class="p-2">{{ $line->account }}</td>
                <td class="p-2 text-right">{{ $line->debit > 0 ? number_format($line->debit, 2) : '—' }}</td>
                <td class="p-2 text-right">{{ $line->credit > 0 ? number_format($line->credit, 2) : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@empty
<div class="bg-white rounded-xl shadow-sm p-5 text-gray-400">{{ __('No journal entries yet. Entries appear here automatically once sales, purchases, expenses or payments are recorded after this feature is installed — or post an opening balance (admin) to start the books.') }}</div>
@endforelse

<div class="mt-4">{{ $entries->links() }}</div>
@endsection
