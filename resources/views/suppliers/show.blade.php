@extends('layouts.app')
@section('title', $supplier->name . ' — Supplier Detail')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ $supplier->name }} — Supplier Detail</h1>
    <a href="{{ route('suppliers.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back to Suppliers</a>
</div>

<p class="text-gray-600 mb-4">{{ $supplier->phone }} @if($supplier->email) · {{ $supplier->email }} @endif</p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Received PO Total</div>
        <div class="text-2xl font-bold">{{ number_format($dues['received_total'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Value of purchase orders actually received from this supplier</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Pending PO Total (not yet received)</div>
        <div class="text-2xl font-bold">{{ number_format($dues['pending_total'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Ordered but not received — not part of what is owed yet</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">Due (before supplier payments)</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($dues['due'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Received total minus recorded supplier payments ({{ number_format($dues['paid'], 2) }})</div>
    </div>
</div>

<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 text-sm text-yellow-800">
    <strong>Please note:</strong> there is no supplier payments record yet, so "Due" above currently equals the received PO total. It is the value received, before any payments made to this supplier — not a settled amount owed. A supplier payments table, payment recording, and including supplier dues in Capital Liabilities are still needed (see the package CHANGES.txt).
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
<h2 class="font-semibold p-4 border-b">Received Purchase Orders</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">PO #</th><th class="p-3">Date</th><th class="p-3">Total</th></tr></thead>
    <tbody>
    @forelse($receivedOrders as $po)
        <tr class="border-t">
            <td class="p-3">{{ $po->id }}</td>
            <td class="p-3">{{ $po->created_at->format('Y-m-d') }}</td>
            <td class="p-3 font-semibold">{{ number_format($po->total, 2) }}</td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="3">No received purchase orders yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4 mb-6">{{ $receivedOrders->links() }}</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<h2 class="font-semibold p-4 border-b">Pending Purchase Orders</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">PO #</th><th class="p-3">Date</th><th class="p-3">Total</th></tr></thead>
    <tbody>
    @forelse($pendingOrders as $po)
        <tr class="border-t">
            <td class="p-3">{{ $po->id }}</td>
            <td class="p-3">{{ $po->created_at->format('Y-m-d') }}</td>
            <td class="p-3 font-semibold">{{ number_format($po->total, 2) }}</td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="3">No pending purchase orders.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
