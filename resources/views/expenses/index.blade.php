@extends('layouts.app')
@section('title', 'Expenses')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Expenses</h1>
    <a href="{{ route('reports.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back to Reports</a>
</div>

<form method="GET" data-ajax-filter="expenses" class="flex gap-2 mb-4">
    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">Filter</button>
</form>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h2 class="font-semibold mb-3">Record an Expense</h2>
    <form method="POST" action="{{ route('expenses.store') }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="flex gap-2">
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-green-500 has-[:checked]:bg-green-50 text-sm">
                <input type="radio" name="type" value="cash_in" checked> Cash In
            </label>
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50 text-sm">
                <input type="radio" name="type" value="cash_out"> Cash Out
            </label>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="border rounded px-3 py-2 w-36">
            @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Date</label>
            <input type="date" name="expense_date" value="{{ now()->toDateString() }}" required class="border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Category (optional)</label>
            <input name="category" placeholder="e.g. Rent, Utilities" class="border rounded px-3 py-2 w-40">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm text-gray-600 mb-1">Note (optional)</label>
            <input name="note" placeholder="e.g. Shop electricity bill for September" class="border rounded px-3 py-2 w-full">
        </div>
        <button class="btn btn-solid-green">Save</button>
    </form>
    <p class="text-xs text-gray-400 mt-3">
        <strong>Cash In</strong> = you're putting fresh money into the business to cover this expense right now — it counts toward both Total Investment and Total Expenses (so Net Remaining doesn't move).
        <strong>Cash Out</strong> = paying from money the business already has — it only counts toward Total Expenses, so Net Remaining goes down.
    </p>
</div>

<div data-ajax-list="expenses">
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-2">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Total Investment</div>
        <div class="text-2xl font-bold text-green-700">{{ number_format($totalInvestment, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Stock Value {{ number_format($stockValue, 2) }} + Cash Injected {{ number_format($cashInjected, 2) }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Total Expenses</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($totalExpenses, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Everything ever spent (Cash In + Cash Out)</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Net Investment Remaining</div>
        <div class="text-2xl font-bold {{ $netRemaining < 0 ? 'text-red-600' : '' }}">{{ number_format($netRemaining, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Investment − Expenses</div>
    </div>
</div>
<p class="text-xs text-gray-400 mb-6">These three figures are your current overall standing (as of right now) — the date filter below only controls which past entries show in the table, it doesn't change these.</p>

@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3 print:hidden">
    <span data-bulk-count="expenses" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="expenses"
            data-action-url="{{ route('expenses.destroy-selected') }}"
            data-confirm-message="Move the selected expenses to Trash?">
        Delete Selected
    </button>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="expenses"></th>@endif
        <th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Category</th><th class="p-3">Amount</th><th class="p-3">Note</th><th class="p-3">Recorded By</th>
        @if(auth()->user()->role === 'admin')<th class="p-3">Actions</th>@endif
    </tr></thead>
    <tbody>
    @forelse($expenses as $e)
        <tr class="border-t">
            @if(auth()->user()->role === 'admin')<td class="p-3"><input type="checkbox" data-bulk-item="expenses" value="{{ $e->id }}"></td>@endif
            <td class="p-3">{{ $e->expense_date->format('Y-m-d') }}</td>
            <td class="p-3">
                @if($e->type === 'cash_in')
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Cash In</span>
                @else
                    <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">Cash Out</span>
                @endif
            </td>
            <td class="p-3">{{ $e->category ?? '—' }}</td>
            <td class="p-3 font-semibold">{{ number_format($e->amount, 2) }}</td>
            <td class="p-3 text-gray-500">{{ $e->note }}</td>
            <td class="p-3">{{ $e->user->name ?? '—' }}</td>
            @if(auth()->user()->role === 'admin')
            <td class="p-3">
                <form method="POST" action="{{ route('expenses.destroy', $e) }}" class="inline confirm-submit" data-confirm-message="Move this expense to Trash?">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
            </td>
            @endif
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="{{ auth()->user()->role === 'admin' ? 7 : 5 }}">No expenses recorded for this period.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $expenses->links() }}</div>
</div>
@endsection
