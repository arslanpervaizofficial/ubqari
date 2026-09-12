@extends('layouts.app')
@section('title', 'Walk-in Customers — Report')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Walk-in Customers — Report</h1>
    <a href="{{ route('reports.customers') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back</a>
</div>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
    <div class="bg-white p-5 rounded-xl shadow-sm"><div class="text-gray-500 text-sm">Total Purchased</div><div class="text-2xl font-bold">{{ number_format($totalPurchased,2) }}</div></div>
    <div class="bg-white p-5 rounded-xl shadow-sm"><div class="text-gray-500 text-sm">Orders</div><div class="text-2xl font-bold">{{ $orders->total() }}</div></div>
    <div class="bg-white p-5 rounded-xl shadow-sm"><div class="text-gray-500 text-sm">Total Returns (net of discount)</div><div class="text-2xl font-bold">{{ number_format($returns->sum('net_amount'),2) }}</div></div>
</div>

{{--
    Deleting orders/returns directly from a report screen was tried and then
    deliberately turned back off: deletion is a data-changing action with
    real consequences (stock gets recounted, customer balances shift), and
    that belongs on the actual Orders page and Stock Returns page — not
    tucked into a read-only report someone opened just to check numbers.
    Left in as a comment (rather than deleted outright) in case it's wanted
    back later; if so, restore this block AND the matching checkbox/Actions
    columns + per-row Delete forms below, and put the colspan back to 7.

@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3 print:hidden">
    <span data-bulk-count="walk-in-orders" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="walk-in-orders"
            data-action-url="{{ route('orders.destroy-selected') }}"
            data-confirm-message="Move the selected orders to Trash? Stock will be adjusted.">
        Delete Selected
    </button>
</div>
@endif
--}}
<div data-ajax-list="walk-in-orders">
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<h2 class="font-semibold p-4 border-b">Walk-in Orders</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        {{-- <th class="p-3"><input type="checkbox" data-bulk-select-all="walk-in-orders"></th> --}}
        <th class="p-3">Order #</th><th class="p-3">Date</th><th class="p-3">Items</th><th class="p-3">Total</th><th class="p-3">Status</th>
        {{-- <th class="p-3">Actions</th> --}}
    </tr></thead>
    <tbody>
    @forelse($orders as $o)
        <tr class="border-t">
            {{-- <td class="p-3"><input type="checkbox" data-bulk-item="walk-in-orders" value="{{ $o->id }}"></td> --}}
            <td class="p-3">{{ $o->order_number }}</td>
            <td class="p-3">{{ $o->created_at->format('Y-m-d') }}</td>
            <td class="p-3">{{ $o->items->count() }}</td>
            <td class="p-3">{{ number_format($o->total,2) }}</td>
            <td class="p-3">
                @if($o->status === 'completed')
                    @if($o->due_amount > 0)
                        <span class="text-amber-600 font-medium">Pending</span>
                    @else
                        <span class="text-green-700 font-medium">Cleared</span>
                    @endif
                @else
                    {{ ucfirst($o->status) }}
                @endif
            </td>
            {{--
            <td class="p-3">
                <form method="POST" action="{{ route('orders.destroy', $o) }}" class="inline confirm-submit" data-confirm-message="Move order {{ $o->order_number }} to Trash? Stock will be recounted automatically.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
            </td>
            --}}
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="5">No walk-in orders yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $orders->links() }}</div>
</div>

@if($returns->count())
<div class="bg-white rounded-xl shadow-sm overflow-x-auto mt-6">
<h2 class="font-semibold p-4 border-b">Returns from Walk-in Customers</h2>
{{--
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 px-4 pt-3 print:hidden">
    <span data-bulk-count="walk-in-returns" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="walk-in-returns"
            data-action-url="{{ route('stock-returns.destroy-selected') }}"
            data-confirm-message="Move the selected returns to Trash? Stock will be adjusted back.">
        Delete Selected
    </button>
</div>
@endif
--}}
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        {{-- <th class="p-3"><input type="checkbox" data-bulk-select-all="walk-in-returns"></th> --}}
        <th class="p-3">Date</th><th class="p-3">Product</th><th class="p-3">Qty</th><th class="p-3">Unit Price</th><th class="p-3">Discount</th><th class="p-3">Net Value</th><th class="p-3">Reason</th>
        {{-- <th class="p-3">Actions</th> --}}
    </tr></thead>
    <tbody>
    @foreach($returns as $r)
        <tr class="border-t">
            {{-- <td class="p-3"><input type="checkbox" data-bulk-item="walk-in-returns" value="{{ $r->id }}"></td> --}}
            <td class="p-3">{{ $r->created_at->format('Y-m-d') }}</td>
            <td class="p-3">{{ $r->product->name ?? '—' }}</td>
            <td class="p-3">{{ $r->quantity }}</td>
            <td class="p-3">{{ number_format($r->unit_price, 2) }}</td>
            <td class="p-3">{{ $r->discount_percent > 0 ? $r->discount_percent.'%' : '—' }}</td>
            <td class="p-3 font-medium">{{ number_format($r->net_amount, 2) }}</td>
            <td class="p-3 text-gray-500">{{ $r->reason }}</td>
            {{--
            <td class="p-3">
                <form method="POST" action="{{ route('stock-returns.destroy', $r) }}" class="inline confirm-submit" data-confirm-message="Move this return to Trash? Stock will be adjusted back.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
            </td>
            --}}
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endif
@endsection
