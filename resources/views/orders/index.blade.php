@extends('layouts.app')
@section('title', 'Orders')
@section('content')
<h1 class="text-2xl font-bold mb-4">Orders</h1>

<form method="GET" data-ajax-filter="orders" class="flex flex-wrap items-end gap-2 mb-4">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Search order # / ID</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="e.g. ORD-20260907 or 42" class="border rounded px-3 w-56">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Range</label>
        <select name="range" id="range-select" class="border rounded px-3">
            <option value="today" @selected($range === 'today')>Daily (Today)</option>
            <option value="week" @selected($range === 'week')>Weekly (This Week)</option>
            <option value="month" @selected($range === 'month')>Monthly (This Month)</option>
            <option value="custom" @selected($range === 'custom')>Custom / Calendar</option>
            <option value="all" @selected($range === 'all')>All Time</option>
        </select>
    </div>
    <div id="custom-range-fields" class="flex items-end gap-2 {{ $range === 'custom' ? '' : 'hidden' }}">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="border rounded px-3">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="border rounded px-3">
        </div>
    </div>
    <button class="btn btn-gray">Filter</button>
</form>

<div data-ajax-list="orders">
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="orders" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="orders"
            data-action-url="{{ route('orders.destroy-selected') }}"
            data-confirm-message="Move the selected orders to Trash? Stock and customer balances will be adjusted.">
        Delete Selected
    </button>
    <button type="button" class="btn btn-red" data-bulk-all-submit
            data-action-url="{{ route('orders.destroy-all') }}"
            data-confirm-message="Move ALL orders in this filtered list to Trash? Stock and customer balances will be adjusted.">
        Delete All
    </button>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left">
        <tr>
            @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="orders"></th>@endif
            <th class="p-3">Order #</th>
            <th class="p-3">Date</th>
            <th class="p-3">Customer</th>
            <th class="p-3">Cashier</th>
            <th class="p-3">Items</th>
            <th class="p-3">Total</th>
            <th class="p-3">Status</th>
            <th class="p-3">Actions</th>
        </tr>
    </thead>
    <tbody>
    @forelse($orders as $o)
        <tr class="border-t">
            @if(auth()->user()->role === 'admin')<td class="p-3">@if(in_array($o->status, ['completed', 'cancelled']))<input type="checkbox" data-bulk-item="orders" value="{{ $o->id }}">@endif</td>@endif
            <td class="p-3 font-medium">{{ $o->order_number }}</td>
            <td class="p-3">{{ $o->created_at->format('Y-m-d H:i') }}</td>
            <td class="p-3">{{ $o->customer->name ?? 'Walk-in' }}</td>
            <td class="p-3">{{ $o->cashier->name ?? '—' }}</td>
            <td class="p-3">{{ $o->items_count }}</td>
            <td class="p-3">{{ number_format($o->total, 2) }}</td>
            <td class="p-3">
                <span class="px-2 py-1 rounded text-xs {{ $o->status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $o->status }}</span>
            </td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <a href="{{ route('pos.invoice', $o) }}" class="btn btn-gray">View</a>
                @if($o->status === 'completed')
                <form method="POST" action="{{ route('orders.edit', $o) }}" class="inline">
                    @csrf
                    <button class="btn btn-blue">Update</button>
                </form>
                @endif
                @if(auth()->user()->role === 'admin' && in_array($o->status, ['completed', 'cancelled']))
                <form method="POST" action="{{ route('orders.destroy', $o) }}" class="inline confirm-submit" data-confirm-message="Move order {{ $o->order_number }} to Trash? Stock and customer balance will be recounted automatically.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="9">No orders in this range.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $orders->links() }}</div>
</div>

<script>
document.getElementById('range-select').addEventListener('change', function () {
    document.getElementById('custom-range-fields').classList.toggle('hidden', this.value !== 'custom');
});
</script>
@endsection
