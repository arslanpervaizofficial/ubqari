@extends('layouts.app')
@section('title', __('Total Capital Report'))
@section('content')
<style>
    body.capital-hints-off .capital-hint { display: none !important; }
    @media print {
        body.capital-hints-off .capital-hint { display: none !important; }
        #capital-hints-toggle { display: none !important; }
    }
</style>
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Total Capital Report') }}</h1>
    <div class="flex gap-2">
        <button type="button" id="capital-hints-toggle" class="btn btn-gray print:hidden">{{ __('Hints: Off') }}</button>
        @include('reports._export_pdf')
        <a href="{{ route('reports.export.capital', request()->only(['from', 'to', 'year', 'view', 'month'])) }}" class="btn btn-solid-green print:hidden">{{ __('Export CSV') }}</a>
        <a href="{{ route('reports.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back to Reports') }}</a>
    </div>
</div>

<p class="text-xs text-gray-400 mb-4 capital-hint">{{ __('The figures below (Investment, Liabilities, Standard Margin) reflect your current overall standing — they aren\'t affected by the date filter further down, which only controls the category breakdown and entry list at the very bottom.') }}</p>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Investment') }}</div>
        <div class="text-2xl font-bold text-green-700">{{ number_format($totalInvestment, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('Current Stock Value') }} {{ number_format($stockValue, 2) }} + {{ __('Cash Injected') }} {{ number_format($cashInjected, 2) }} + {{ __('Receivables') }} {{ number_format($totalReceivables, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('What is currently deployed in the business — falls when stock sells, and re-buying with sale cash does not double-count the same money.') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Remaining Investment') }}</div>
        <div class="text-2xl font-bold text-blue-700">{{ number_format($remainingInvestment, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('Current Stock Value') }} {{ number_format($stockValue, 2) }} + {{ __('Cash Injected') }} {{ number_format($cashInjected, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('What\'s left of the investment after sales — shrinks as stock is sold.') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Out (Expenses)') }}</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($totalExpenses, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('Everything actually spent (Cash Out only) — Cash In is capital, tracked separately above') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Liabilities') }}</div>
        <div class="text-2xl font-bold {{ $totalLiabilities > 0 ? 'text-red-600' : '' }}">{{ number_format($totalLiabilities, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('Cash borrowing') }} {{ number_format($totalLiabilities - ($supplierLiabilities ?? 0), 2) }} {{ __('(from') }} <a href="{{ route('cash-management.index') }}" class="underline">{{ __('Cash Management') }}</a>{{ __(') + Supplier dues') }} {{ number_format($supplierLiabilities ?? 0, 2) }} {{ __('(from') }} <a href="{{ route('suppliers.index') }}" class="underline">{{ __('Suppliers') }}</a>{{ __(') — now subtracted below in Net Capital') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Receivables') }}</div>
        <div class="text-2xl font-bold {{ $totalReceivables > 0 ? 'text-blue-700' : '' }}">{{ number_format($totalReceivables, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('Wholesale credit') }} {{ number_format($customerReceivables, 2) }} + {{ __('Walk-in dues') }} {{ number_format($walkInDue, 2) }} {{ __('— owed BY customers (credit balance on') }} <a href="{{ route('customers.index') }}" class="underline">{{ __('Customers') }}</a>{{ __(', plus completed walk-in orders with no customer, due_amount still outstanding) — now added below in Net Capital') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Advances to Suppliers') }}</div>
        <div class="text-2xl font-bold {{ ($supplierAdvances ?? 0) > 0 ? 'text-blue-700' : '' }}">{{ number_format($supplierAdvances ?? 0, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('Negative supplier balances — money paid to suppliers in advance, owed back to the business (from') }} <a href="{{ route('suppliers.index') }}" class="underline">{{ __('Suppliers') }}</a>{{ __(') — now added below in Net Capital') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Net Capital') }}</div>
        <div class="text-2xl font-bold {{ $netCapital < 0 ? 'text-red-600' : '' }}">{{ number_format($netCapital, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1 capital-hint">{{ __('Total Investment − Expenses − Liabilities + Advances to Suppliers') }}</div>
    </div>
</div>

<p class="text-xs text-gray-400 mb-6 capital-hint">
    {{ __('This is a read-only report. To inject capital or record a liability, use the') }}
    <a href="{{ route('cash-management.index') }}" class="underline font-medium">{{ __('Cash Management') }}</a> {{ __('page — to record an actual expense, use the') }}
    <a href="{{ route('expenses.index') }}" class="underline font-medium">{{ __('Expenses') }}</a> page.
</p>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h2 class="font-semibold mb-1">{{ __('Standard Margin') }}</h2>
    <p class="text-xs text-gray-400 mb-4 capital-hint">
        {{ __('Revenue is each completed order\'s actual settled total — item-level and customer-level discounts (e.g. a wholesale customer\'s standing discount vs a walk-in customer\'s full price) are already netted out. Cost of Goods Sold uses each product\'s CURRENT Purchase Price minus its Purchase Discount % on the Products page (i.e. the Net Purchase Price shown there) — but since there\'s no historical cost snapshot per sale, COGS (not Revenue) shifts if a product\'s cost is edited after the sale, so this is a close estimate, not an exact figure. Customer returns are subtracted from both Revenue (net amount) and COGS (returned quantity at current net cost), so a fully returned sale contributes zero margin. It\'s a live figure, not cached — it reflects the latest billing, stock purchases, and product-price edits the moment you load this page.') }}
    </p>
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div>
            <div class="text-gray-500 text-sm">{{ __('Total Revenue') }}</div>
            <div class="text-xl font-bold">{{ number_format($totalRevenue, 2) }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-sm">{{ __('Est. Cost of Goods Sold') }}</div>
            <div class="text-xl font-bold">{{ number_format($approxCogs, 2) }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-sm">{{ __('Est. Gross Margin') }}</div>
            <div class="text-xl font-bold {{ $approxGrossMargin < 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($approxGrossMargin, 2) }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-sm">{{ __('Est. Net Profit (after expenses)') }}</div>
            <div class="text-xl font-bold {{ $approxNetProfit < 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($approxNetProfit, 2) }}</div>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
<div class="flex justify-between items-center p-4 border-b flex-wrap gap-2">
    <h2 class="font-semibold">{{ $breakdownView === 'monthly' ? __('Daily Breakdown') : __('Monthly Breakdown') }}</h2>
    <form method="GET" data-ajax-filter="capital" class="flex gap-2 items-center print:hidden">
        <input type="hidden" name="from" value="{{ $from }}">
        <input type="hidden" name="to" value="{{ $to }}">
        <label class="text-sm text-gray-500">{{ __('View') }}</label>
        <select name="view" onchange="this.form.submit()" class="border rounded px-2 py-1 text-sm">
            <option value="yearly" {{ $breakdownView === 'yearly' ? 'selected' : '' }}>{{ __('Yearly (monthly rows)') }}</option>
            <option value="monthly" {{ $breakdownView === 'monthly' ? 'selected' : '' }}>{{ __('Monthly (daily rows)') }}</option>
        </select>
        <label class="text-sm text-gray-500">{{ __('Year') }}</label>
        <select name="year" onchange="this.form.submit()" class="border rounded px-2 py-1 text-sm">
            @for($y = now()->year; $y >= now()->year - 4; $y--)
                <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
            @endfor
        </select>
        @if($breakdownView === 'monthly')
        <label class="text-sm text-gray-500">{{ __('Month') }}</label>
        <select name="month" onchange="this.form.submit()" class="border rounded px-2 py-1 text-sm">
            @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" {{ $m == $month ? 'selected' : '' }}>{{ \Carbon\Carbon::create($year, $m, 1)->format('M') }}</option>
            @endfor
        </select>
        @endif
    </form>
</div>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">{{ $breakdownView === 'monthly' ? 'Day' : __('Month') }}</th><th class="p-3">{{ __('Cash In') }}</th><th class="p-3">{{ __('Cash Out') }}</th>
        <th class="p-3">{{ __('Revenue') }}</th><th class="p-3">{{ __('Est. COGS') }}</th>
        <th class="p-3">{{ __('Gross Margin') }}</th><th class="p-3">{{ __('Net Profit') }}</th>
    </tr></thead>
    <tbody>
    @foreach($monthly as $row)
        @php
            $isCurrent = $breakdownView === 'monthly'
                ? ($row['day'] == now()->day && $month == now()->month && $year == now()->year)
                : ($row['month'] == now()->month && $year == now()->year);
        @endphp
        <tr class="border-t {{ $isCurrent ? 'bg-blue-50' : '' }}">
            <td class="p-3 font-medium">{{ $row['label'] }}</td>
            <td class="p-3 text-green-700">{{ number_format($row['cash_in'], 2) }}</td>
            <td class="p-3 text-red-600">{{ number_format($row['cash_out'], 2) }}</td>
            <td class="p-3">{{ number_format($row['revenue'], 2) }}</td>
            <td class="p-3">{{ number_format($row['cogs'], 2) }}</td>
            <td class="p-3 {{ $row['gross_margin'] < 0 ? 'text-red-600' : '' }}">{{ number_format($row['gross_margin'], 2) }}</td>
            <td class="p-3 font-semibold {{ $row['net_profit'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($row['net_profit'], 2) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr class="border-t-2 font-semibold bg-gray-50">
            <td class="p-3">{{ __('Total (') }}{{ $breakdownView === 'monthly' ? \Carbon\Carbon::create($year, $month, 1)->format('M Y') : $year }})</td>
            <td class="p-3 text-green-700">{{ number_format(collect($monthly)->sum('cash_in'), 2) }}</td>
            <td class="p-3 text-red-600">{{ number_format(collect($monthly)->sum('cash_out'), 2) }}</td>
            <td class="p-3">{{ number_format(collect($monthly)->sum('revenue'), 2) }}</td>
            <td class="p-3">{{ number_format(collect($monthly)->sum('cogs'), 2) }}</td>
            <td class="p-3 {{ collect($monthly)->sum('gross_margin') < 0 ? 'text-red-600' : '' }}">{{ number_format(collect($monthly)->sum('gross_margin'), 2) }}</td>
            <td class="p-3 {{ collect($monthly)->sum('net_profit') < 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format(collect($monthly)->sum('net_profit'), 2) }}</td>
        </tr>
    </tfoot>
</table>
</div>

<form method="GET" data-ajax-filter="capital" class="flex gap-2 mb-4 print:hidden">
    <input type="hidden" name="view" value="{{ $breakdownView }}">
    <input type="hidden" name="year" value="{{ $year }}">
    <input type="hidden" name="month" value="{{ $month }}">
    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">{{ __('Filter Entry List') }}</button>
</form>

<div data-ajax-list="capital">
@if($byCategory->count())
<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
<h2 class="font-semibold p-4 border-b">{{ __('By Category (selected date range)') }}</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Category') }}</th><th class="p-3">{{ __('Cash In') }}</th><th class="p-3">{{ __('Cash Out') }}</th><th class="p-3">{{ __('Total') }}</th></tr></thead>
    <tbody>
    @foreach($byCategory as $category => $sums)
        <tr class="border-t">
            <td class="p-3">{{ $category }}</td>
            <td class="p-3 text-green-700">{{ number_format($sums['cash_in'], 2) }}</td>
            <td class="p-3 text-red-600">{{ number_format($sums['cash_out'], 2) }}</td>
            <td class="p-3 font-medium">{{ number_format($sums['cash_in'] + $sums['cash_out'], 2) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr class="border-t-2 font-semibold bg-gray-50">
            <td class="p-3">{{ __('Total') }}</td>
            <td class="p-3 text-green-700">{{ number_format($byCategory->sum('cash_in'), 2) }}</td>
            <td class="p-3 text-red-600">{{ number_format($byCategory->sum('cash_out'), 2) }}</td>
            <td class="p-3">{{ number_format($byCategory->sum('cash_in') + $byCategory->sum('cash_out'), 2) }}</td>
        </tr>
    </tfoot>
</table>
</div>
@endif

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<h2 class="font-semibold p-4 border-b">{{ __('All Entries (selected date range)') }}</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Type') }}</th><th class="p-3">{{ __('Category') }}</th><th class="p-3">{{ __('Amount') }}</th><th class="p-3">{{ __('Note') }}</th><th class="p-3">{{ __('Recorded By') }}</th></tr></thead>
    <tbody>
    @forelse($expenses as $e)
        <tr class="border-t">
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
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="6">{{ __('No expenses recorded for this period.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
</div>

<p class="text-sm text-gray-500 mt-4 capital-hint">{{ __('Manage individual entries on the') }} <a href="{{ route('expenses.index') }}" class="text-blue-600 hover:underline">{{ __('Expenses') }}</a> page.</p>

<script>
(function () {
    var btn = document.getElementById('capital-hints-toggle');
    if (!btn) return;
    function applyHints(on) {
        document.body.classList.toggle('capital-hints-off', !on);
        document.body.classList.toggle('capital-hints-on', on);
        btn.textContent = on ? 'Hints: On' : 'Hints: Off';
        try { localStorage.setItem('capital_hints', on ? 'on' : 'off'); } catch (e) {}
    }
    var stored = null;
    try { stored = localStorage.getItem('capital_hints'); } catch (e) {}
    // Default is OFF, as requested. A previous choice on this browser is remembered.
    // Export PDF is the browser print dialog, so the same on/off state applies to the PDF.
    applyHints(stored === 'on');
    btn.addEventListener('click', function () {
        applyHints(!document.body.classList.contains('capital-hints-on'));
    });
})();
</script>
@endsection
