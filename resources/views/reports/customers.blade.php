@extends('layouts.app')
@section('title', 'Customer Report')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Customer Report</h1>
    <a href="{{ route('reports.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back to Reports</a>
</div>
<h2 class="font-semibold text-gray-600 mb-2">Walk-in Customers</h2>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">Name</th><th class="p-3">Orders</th><th class="p-3">Total Purchased</th><th class="p-3">Actions</th></tr></thead>
    <tbody>
        <tr class="border-t">
            <td class="p-3 font-medium">Walk-in (no account)</td>
            <td class="p-3">{{ $walkIn['orders_count'] }}</td>
            <td class="p-3">{{ number_format($walkIn['total_purchased'], 2) }}</td>
            <td class="p-3"><a href="{{ route('reports.walk-in-detail') }}" class="btn btn-blue">View Report</a></td>
        </tr>
    </tbody>
</table>
</div>

<h2 class="font-semibold text-gray-600 mb-2">Registered Customers</h2>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3"></th><th class="p-3">Name</th><th class="p-3">Orders</th><th class="p-3">Total Purchased</th><th class="p-3">Credit Balance</th><th class="p-3">Actions</th></tr></thead>
    <tbody>
    @foreach($customers as $c)
        <tr class="border-t">
            <td class="p-3"><img src="{{ $c->image_url }}" class="w-8 h-8 rounded-full object-cover"></td>
            <td class="p-3 font-medium">{{ $c->name }}</td>
            <td class="p-3">{{ $c->orders_count }}</td>
            <td class="p-3">{{ number_format($c->total_purchased ?? 0, 2) }}</td>
            <td class="p-3 {{ $c->credit_balance > 0 ? 'text-red-600 font-semibold' : '' }}">{{ number_format($c->credit_balance, 2) }}</td>
            <td class="p-3"><a href="{{ route('reports.customer-detail', $c) }}" class="btn btn-blue">View Report</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endsection
