@extends('layouts.app')
@section('title', 'Purchase Report')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Purchase Report</h1>
    <a href="{{ route('reports.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back to Reports</a>
</div>
<form method="GET" data-ajax-filter="purchases" class="flex gap-2 mb-4">
    <input type="date" name="from" value="{{ $from }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $to }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">Filter</button>
</form>
<div data-ajax-list="purchases">
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Total Purchase Value</div>
        <div class="text-2xl font-bold">{{ number_format($totalPurchaseValue, 2) }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Returned to Supplier</div>
        <div class="text-2xl font-bold text-red-600">-{{ number_format($totalReturnedValue, 2) }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Net Purchase Value</div>
        <div class="text-2xl font-bold text-green-700">{{ number_format($netPurchaseValue, 2) }}</div>
    </div>
</div>

@if($supplierReturns->count())
<div class="bg-white rounded-xl shadow-sm p-4 mb-4">
    <h2 class="font-semibold mb-2">Returned to Supplier</h2>
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 border-b"><tr><th class="py-1">Date</th><th>Product</th><th>Supplier</th><th>Qty</th><th class="text-right">Value</th></tr></thead>
        <tbody>
        @foreach($supplierReturns as $r)
            <tr class="border-t">
                <td class="py-1">{{ $r->created_at->format('Y-m-d') }}</td>
                <td>{{ $r->product->name ?? '—' }}</td>
                <td>{{ $r->supplier->name ?? '—' }}</td>
                <td>{{ $r->quantity }}</td>
                <td class="text-right">{{ number_format($r->net_amount, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
<div class="space-y-3">
@foreach($purchaseOrders as $po)
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex justify-between items-center mb-2">
            <div class="font-semibold">PO #{{ $po->id }} — {{ $po->supplier->name }}</div>
            <span class="text-xs px-2 py-1 rounded-full {{ $po->status === 'received' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ $po->status }}</span>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500 border-b">
                <tr><th class="py-1">Product</th><th>Quantity</th><th>Cost Price</th><th>Disc %</th><th class="text-right">Line Total</th></tr>
            </thead>
            <tbody>
            @foreach($po->items as $item)
                <tr class="border-t"><td class="py-1">{{ $item->product->name ?? '—' }}</td><td>{{ $item->quantity }}</td><td>{{ number_format($item->cost_price,2) }}</td><td>{{ $item->discount_percent }}%</td><td class="text-right">{{ number_format($item->line_total,2) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <div class="text-right font-semibold mt-2">Total: {{ number_format($po->total, 2) }}</div>
    </div>
@endforeach
</div>
</div>
@endsection
