@extends('layouts.app')
@section('title', __('Expenses'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Expenses') }}</h1>
    <a href="{{ route('reports.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back to Reports') }}</a>
</div>

<form method="GET" data-ajax-filter="expenses" class="flex gap-2 mb-4">
    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">{{ __('Filter') }}</button>
</form>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h2 class="font-semibold mb-3">{{ __('Record an Expense') }}</h2>
    <form method="POST" action="{{ route('expenses.store') }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        {{-- Cash Out only: Cash In is recorded exclusively from Cash Management (Add Capital) now. The controller also rejects any cash_in submitted without that page's marker, so removing the option here is backed by server-side validation, not just the UI. --}}
        <input type="hidden" name="type" value="cash_out">
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Amount') }}</label>
            <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="border rounded px-3 py-2 w-36">
            @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Date') }}</label>
            <input type="date" name="expense_date" value="{{ now()->toDateString() }}" required class="border rounded px-3 py-2">
            @error('expense_date') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Category') }}</label>
            <input name="category" placeholder="{{ __('e.g. Rent, Utilities') }}" required data-label="Category" class="border rounded px-3 py-2 w-40">
            @error('category') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Note') }}</label>
            <input name="note" placeholder="{{ __('e.g. Shop electricity bill for September') }}" required data-label="Note" class="border rounded px-3 py-2 w-full">
            @error('note') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <button class="btn btn-solid-green">{{ __('Save') }}</button>
    </form>
    <p class="text-xs text-gray-400 mt-3">
        <strong>{{ __('Cash Out') }}</strong> {{ __('= paying from money the business already has — it only counts toward Total Expenses, so Net Remaining goes down. Putting fresh money into the business (') }}<strong>{{ __('Cash In') }}</strong>{{ __(') is recorded only from the') }} <a href="{{ route('cash-management.index') }}" class="underline">{{ __('Cash Management') }}</a> {{ __('page (Add Capital), not from here.') }}
    </p>
</div>

<div data-ajax-list="expenses">
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-2">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Current Investment') }}</div>
        <div class="text-2xl font-bold text-green-700">{{ number_format($totalInvestment, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Stock Value') }} {{ number_format($stockValue, 2) }} + {{ __('Cash Injected') }} {{ number_format($cashInjected, 2) }} + {{ __('Receivables') }} {{ number_format($totalReceivables, 2) }} {{ __('— same figure as "Total Investment" on the Capital Report') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Expenses') }}</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($totalExpenses, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Everything actually spent (Cash Out only) — Cash In is capital, tracked separately above') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Net Investment Remaining') }}</div>
        <div class="text-2xl font-bold {{ $netRemaining < 0 ? 'text-red-600' : '' }}">{{ number_format($netRemaining, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Investment − Expenses − Liabilities (Cash borrowing') }} {{ number_format($totalLiabilities - ($supplierLiabilities ?? 0), 2) }} + {{ __('Supplier dues') }} {{ number_format($supplierLiabilities ?? 0, 2) }} + {{ __('Advances to Suppliers') }} {{ number_format($supplierAdvances ?? 0, 2) }}{{ __(') — same figure as "Net Capital" on the Capital Report') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Advances to Suppliers') }}</div>
        <div class="text-2xl font-bold {{ ($supplierAdvances ?? 0) > 0 ? 'text-blue-700' : '' }}">{{ number_format($supplierAdvances ?? 0, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Negative supplier balances — money paid to suppliers in advance, owed back to the business (from') }} <a href="{{ route('suppliers.index') }}" class="underline">{{ __('Suppliers') }}</a>{{ __(') — now added above in Net Investment Remaining') }}</div>
    </div>
</div>
<p class="text-xs text-gray-400 mb-6">{{ __('These three figures are your current overall standing (as of right now) — the date filter below only controls which past entries show in the table, it doesn\'t change these.') }}</p>

@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3 print:hidden">
    <span data-bulk-count="expenses" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="expenses"
            data-action-url="{{ route('expenses.destroy-selected') }}"
            data-confirm-message="{{ __('Move the selected expenses to Trash?') }}">
        {{ __('Delete Selected') }}
    </button>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="expenses"></th>@endif
        <th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Type') }}</th><th class="p-3">{{ __('Category') }}</th><th class="p-3">{{ __('Amount') }}</th><th class="p-3">{{ __('Note') }}</th><th class="p-3">{{ __('Recorded By') }}</th>
        @if(auth()->user()->role === 'admin')<th class="p-3">{{ __('Actions') }}</th>@endif
    </tr></thead>
    <tbody>
    @forelse($expenses as $e)
        <tr class="border-t">
            @if(auth()->user()->role === 'admin')<td class="p-3"><input type="checkbox" data-bulk-item="expenses" value="{{ $e->id }}"></td>@endif
            <td class="p-3">{{ $e->expense_date->format('Y-m-d') }}</td>
            <td class="p-3">
                @if($e->type === 'cash_in')
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">{{ __('Cash In') }}</span>
                @else
                    <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">{{ __('Cash Out') }}</span>
                @endif
            </td>
            <td class="p-3">{{ $e->category ?? '—' }}</td>
            <td class="p-3 font-semibold">{{ number_format($e->amount, 2) }}</td>
            <td class="p-3 text-gray-500">{{ $e->note }}</td>
            <td class="p-3">{{ $e->user->name ?? '—' }}</td>
            @if(auth()->user()->role === 'admin')
            <td class="p-3">
                <form method="POST" action="{{ route('expenses.destroy', $e) }}" class="inline confirm-submit" data-confirm-message="{{ __('Move this expense to Trash?') }}">
                    @csrf @method('DELETE')<button class="btn btn-red">{{ __('Delete') }}</button>
                </form>
            </td>
            @endif
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="{{ auth()->user()->role === 'admin' ? 7 : 5 }}">{{ __('No expenses recorded for this period.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $expenses->links() }}</div>
</div>
@endsection
