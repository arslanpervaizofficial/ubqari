@extends('layouts.app')
@section('title', 'Stock Returns')
@section('content')
<div class="flex justify-between items-center mb-4 flex-wrap gap-2">
    <h1 class="text-2xl font-bold">Stock Returns</h1>
    <div class="flex gap-2">
        <a href="{{ route('stock-returns.customer-form') }}" class="btn btn-solid-green">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
            Return from Customer
        </a>
        <a href="{{ route('stock-returns.supplier-form') }}" class="btn btn-solid-blue">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9l6-6m0 0l-6 6m6-6H9a6 6 0 000 12h3"/></svg>
            Return to Supplier
        </a>
    </div>
</div>

<form method="GET" data-ajax-filter="stock-returns-results" class="flex flex-wrap items-end gap-2 mb-6">
    <div>
        <label class="block text-xs text-gray-500 mb-1">From</label>
        <input type="date" name="from" value="{{ $from }}" class="border rounded px-3">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">To</label>
        <input type="date" name="to" value="{{ $to }}" class="border rounded px-3">
    </div>
    <button class="btn btn-gray">Filter</button>
    <button type="button" class="btn btn-gray" data-ajax-quick-range="{{ now()->startOfMonth()->toDateString() }}|{{ now()->toDateString() }}">This Month</button>
    <button type="button" class="btn btn-gray" data-ajax-quick-range="{{ now()->subMonth()->startOfMonth()->toDateString() }}|{{ now()->subMonth()->endOfMonth()->toDateString() }}">Last Month</button>
</form>

<div data-ajax-list="stock-returns-results">
<!-- Returns from Customers -->
<div class="flex justify-between items-center mb-3">
    <h2 class="text-lg font-semibold flex items-center gap-2">
        <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
        Returns from Customers
    </h2>
    <div class="text-sm text-gray-500">Total: <span class="font-bold text-green-700">{{ number_format($customerReturnsTotal, 2) }}</span></div>
</div>
<div data-ajax-list="customer-returns" class="mb-8">
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="stock-returns-customer" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="stock-returns-customer"
            data-action-url="{{ route('stock-returns.destroy-selected') }}"
            data-confirm-message="Move the selected customer returns to Trash? Stock will be adjusted back.">
        Delete Selected
    </button>
    <form method="POST" action="{{ route('stock-returns.destroy-all') }}" class="inline confirm-submit"
          data-confirm-message="Move ALL customer returns in this range to Trash? Stock will be adjusted back.">
        @csrf
        <input type="hidden" name="type" value="from_customer">
        <input type="hidden" name="from" value="{{ $from }}">
        <input type="hidden" name="to" value="{{ $to }}">
        <button class="btn btn-red">Delete All</button>
    </form>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="stock-returns-customer"></th>@endif
        <th class="p-3">Date</th><th class="p-3">Product</th><th class="p-3">Customer</th><th class="p-3">Qty</th><th class="p-3">Discount</th><th class="p-3">Net Value</th><th class="p-3">Reason</th>
        @if(auth()->user()->role === 'admin')<th class="p-3">Actions</th>@endif
    </tr></thead>
    <tbody>
    @forelse($customerReturns as $r)
        <tr class="border-t">
            @if(auth()->user()->role === 'admin')<td class="p-3"><input type="checkbox" data-bulk-item="stock-returns-customer" value="{{ $r->id }}"></td>@endif
            <td class="p-3">{{ $r->created_at->format('Y-m-d H:i') }}</td>
            <td class="p-3">{{ $r->product->name ?? '—' }}</td>
            <td class="p-3">{{ $r->customer->name ?? 'Walk-in' }}</td>
            <td class="p-3">{{ $r->quantity }}</td>
            <td class="p-3">{{ $r->discount_percent > 0 ? $r->discount_percent.'%' : '—' }}</td>
            <td class="p-3 font-medium">{{ number_format($r->net_amount, 2) }}</td>
            <td class="p-3 text-gray-500">{{ $r->reason }}</td>
            @if(auth()->user()->role === 'admin')
            <td class="p-3">
                <form method="POST" action="{{ route('stock-returns.destroy', $r) }}" class="inline confirm-submit" data-confirm-message="Move this return to Trash? Stock will be adjusted back. You can restore it anytime from Trash.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
            </td>
            @endif
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="{{ auth()->user()->role === 'admin' ? 8 : 6 }}">No customer returns in this range.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-3">{{ $customerReturns->links() }}</div>
</div>

<!-- Returns to Suppliers -->
<div class="flex justify-between items-center mb-3">
    <h2 class="text-lg font-semibold flex items-center gap-2">
        <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9l6-6m0 0l-6 6m6-6H9a6 6 0 000 12h3"/></svg>
        Returns to Suppliers
    </h2>
    <div class="text-sm text-gray-500">Total: <span class="font-bold text-blue-700">{{ number_format($supplierReturnsTotal, 2) }}</span></div>
</div>
<div data-ajax-list="supplier-returns">
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="stock-returns-supplier" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="stock-returns-supplier"
            data-action-url="{{ route('stock-returns.destroy-selected') }}"
            data-confirm-message="Move the selected supplier returns to Trash? Stock will be adjusted back.">
        Delete Selected
    </button>
    <form method="POST" action="{{ route('stock-returns.destroy-all') }}" class="inline confirm-submit"
          data-confirm-message="Move ALL supplier returns in this range to Trash? Stock will be adjusted back.">
        @csrf
        <input type="hidden" name="type" value="to_supplier">
        <input type="hidden" name="from" value="{{ $from }}">
        <input type="hidden" name="to" value="{{ $to }}">
        <button class="btn btn-red">Delete All</button>
    </form>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="stock-returns-supplier"></th>@endif
        <th class="p-3">Date</th><th class="p-3">Product</th><th class="p-3">Supplier</th><th class="p-3">Qty</th><th class="p-3">Discount</th><th class="p-3">Net Value</th><th class="p-3">Reason</th>
        @if(auth()->user()->role === 'admin')<th class="p-3">Actions</th>@endif
    </tr></thead>
    <tbody>
    @forelse($supplierReturns as $r)
        <tr class="border-t">
            @if(auth()->user()->role === 'admin')<td class="p-3"><input type="checkbox" data-bulk-item="stock-returns-supplier" value="{{ $r->id }}"></td>@endif
            <td class="p-3">{{ $r->created_at->format('Y-m-d H:i') }}</td>
            <td class="p-3">{{ $r->product->name ?? '—' }}</td>
            <td class="p-3">{{ $r->supplier->name ?? '—' }}</td>
            <td class="p-3">{{ $r->quantity }}</td>
            <td class="p-3">{{ $r->discount_percent > 0 ? $r->discount_percent.'%' : '—' }}</td>
            <td class="p-3 font-medium">{{ number_format($r->net_amount, 2) }}</td>
            <td class="p-3 text-gray-500">{{ $r->reason }}</td>
            @if(auth()->user()->role === 'admin')
            <td class="p-3">
                <form method="POST" action="{{ route('stock-returns.destroy', $r) }}" class="inline confirm-submit" data-confirm-message="Move this return to Trash? Stock will be adjusted back. You can restore it anytime from Trash.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
            </td>
            @endif
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="{{ auth()->user()->role === 'admin' ? 8 : 6 }}">No supplier returns in this range.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-3">{{ $supplierReturns->links() }}</div>
</div>
</div>
@endsection
