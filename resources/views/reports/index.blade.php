@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<h1 class="text-2xl font-bold mb-4">Reports</h1>

<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
    <a href="{{ route('reports.stock') }}" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition">
        <div class="w-9 h-9 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center mb-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/></svg>
        </div>
        <div class="font-semibold text-gray-800">Stock Report</div>
        <div class="text-xs text-gray-500 mt-1">Current stock & value per product</div>
    </a>
    <a href="{{ route('reports.purchases') }}" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition">
        <div class="w-9 h-9 rounded-lg bg-purple-100 text-purple-700 flex items-center justify-center mb-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l3-8H6.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m-10 0a2 2 0 100 4 2 2 0 000-4zm10 0a2 2 0 100 4 2 2 0 000-4z"/></svg>
        </div>
        <div class="font-semibold text-gray-800">Purchase Report</div>
        <div class="text-xs text-gray-500 mt-1">Purchase orders by date range</div>
    </a>
    <a href="{{ route('reports.customers') }}" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition">
        <div class="w-9 h-9 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center mb-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-4a4 4 0 100-8 4 4 0 000 8zm6 4a4 4 0 00-3-3.87m-9-8.13a4 4 0 100 8 4 4 0 000-8z"/></svg>
        </div>
        <div class="font-semibold text-gray-800">Customer Report</div>
        <div class="text-xs text-gray-500 mt-1">Per-customer sales & dues</div>
    </a>
    <a href="{{ route('reports.cash-reconciliation') }}" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition">
        <div class="w-9 h-9 rounded-lg bg-green-100 text-green-700 flex items-center justify-center mb-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="font-semibold text-gray-800">Cash Reconciliation</div>
        <div class="text-xs text-gray-500 mt-1">Daily cash count check</div>
    </a>
    <a href="{{ route('stock-returns.index') }}" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition">
        <div class="w-9 h-9 rounded-lg bg-red-100 text-red-700 flex items-center justify-center mb-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
        </div>
        <div class="font-semibold text-gray-800">Returns Report</div>
        <div class="text-xs text-gray-500 mt-1">All customer & supplier returns</div>
    </a>
    <a href="{{ route('reports.capital') }}" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition">
        <div class="w-9 h-9 rounded-lg bg-teal-100 text-teal-700 flex items-center justify-center mb-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .672-3 1.5S10.343 11 12 11s3 .672 3 1.5-1.343 1.5-3 1.5m0-6c1.11 0 2.08.402 2.599 1M12 8V6.5M12 15.5V17m0-9C8.686 8 6 9.79 6 12s2.686 4 6 4 6-1.79 6-4-2.686-4-6-4z"/></svg>
        </div>
        <div class="font-semibold text-gray-800">Total Capital</div>
        <div class="text-xs text-gray-500 mt-1">Business cash In vs Out (Expenses)</div>
    </a>
</div>

<form method="GET" data-ajax-filter="sales-summary" class="flex gap-2 mb-6">
    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">Filter Sales Summary</button>
</form>

<div data-ajax-list="sales-summary">
@if($totalReturnsValue > 0)
    <p class="text-xs text-gray-400 mb-2">
        Gross sales {{ number_format($grossSales, 2) }} minus customer returns {{ number_format($totalReturnsValue, 2) }} in this date range
        (see <a href="{{ route('stock-returns.index') }}" class="underline">Stock Returns</a> for the detail) = net sales below.
    </p>
@endif
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Total Sales ({{ $from }} to {{ $to }}) — net of returns</div>
        <div class="text-2xl font-bold {{ $totalSales < 0 ? 'text-red-600' : '' }}">{{ number_format($totalSales, 2) }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Total Orders</div>
        <div class="text-2xl font-bold">{{ $totalOrders }}</div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <h2 class="font-semibold mb-2">Best-Selling Products (net of returns)</h2>
        <table class="w-full text-sm">
            @forelse($bestSelling as $b)
                <tr class="border-t"><td class="py-1">{{ $b->product->name ?? '—' }}</td><td class="text-right">{{ $b->qty_sold }} sold</td></tr>
            @empty
                <tr><td class="py-2 text-gray-400">No sales in this range.</td></tr>
            @endforelse
        </table>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <h2 class="font-semibold mb-2">Cashier-wise Sales</h2>
        <table class="w-full text-sm">
            @foreach($cashierSales as $c)
                <tr class="border-t"><td class="py-1">{{ $c->cashier->name ?? '—' }}</td><td class="text-right">{{ number_format($c->total_sales,2) }} ({{ $c->order_count }} orders)</td></tr>
            @endforeach
        </table>
    </div>
</div>
</div>
@endsection
