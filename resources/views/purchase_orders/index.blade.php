@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Purchase Orders</h1>
    <a href="{{ route('purchase-orders.create') }}" class="bg-gray-900 text-white px-4 py-2 rounded">+ New PO</a>
</div>
<div data-ajax-list="purchase-orders">
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="purchase-orders" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="purchase-orders"
            data-action-url="{{ route('purchase-orders.destroy-selected') }}"
            data-confirm-message="Move the selected purchase orders to Trash?">
        Delete Selected
    </button>
    <button type="button" class="btn btn-red" data-bulk-all-submit
            data-action-url="{{ route('purchase-orders.destroy-all') }}"
            data-confirm-message="Move ALL purchase orders on this list to Trash?">
        Delete All
    </button>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="purchase-orders"></th>@endif
        <th class="p-3">PO #</th><th class="p-3">Supplier</th><th class="p-3">Total</th><th class="p-3">Status</th><th class="p-3">Actions</th>
    </tr></thead>
    <tbody>
    @foreach($purchaseOrders as $po)
        <tr class="border-t">
            @if(auth()->user()->role === 'admin')<td class="p-3"><input type="checkbox" data-bulk-item="purchase-orders" value="{{ $po->id }}"></td>@endif
            <td class="p-3">#{{ $po->id }}</td>
            <td class="p-3">{{ $po->supplier->name }}</td>
            <td class="p-3">{{ number_format($po->total, 2) }}</td>
            <td class="p-3">
                <span class="px-2 py-1 rounded text-xs {{ $po->status === 'received' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ $po->status }}</span>
            </td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                @if($po->status === 'pending')
                <a href="{{ route('purchase-orders.receive-form', $po) }}" class="btn btn-green !bg-green-600 !text-white">Mark Received</a>
                @endif
                @if(auth()->user()->role === 'admin')
                <form method="POST" action="{{ route('purchase-orders.destroy', $po) }}" class="inline confirm-submit" data-confirm-message="Move PO #{{ $po->id }} to Trash? This doesn't change current stock levels — it only removes the order record.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
<div class="mt-4">{{ $purchaseOrders->links() }}</div>
</div>
@endsection
