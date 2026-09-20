@extends('layouts.app')
@section('title', 'Held Orders')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Held Orders</h1>
    <a href="{{ route('pos.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back to Billing</a>
</div>

<div data-ajax-list="held-orders">
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">Order #</th><th class="p-3">Customer</th><th class="p-3">Cashier</th><th class="p-3">Items</th><th class="p-3">Held Since</th><th class="p-3">Actions</th></tr></thead>
    <tbody>
    @forelse($heldOrders as $h)
        <tr class="border-t">
            <td class="p-3 font-medium">{{ $h->order_number }}</td>
            <td class="p-3">{{ $h->customer->name ?? 'Walk-in' }}</td>
            <td class="p-3">{{ $h->cashier->name ?? '—' }}</td>
            <td class="p-3">{{ $h->items_count }}</td>
            <td class="p-3 text-gray-500">{{ $h->updated_at->diffForHumans() }}</td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <a href="{{ route('pos.held-detail', $h) }}" class="btn btn-gray">View</a>
                <form method="POST" action="{{ route('pos.resume', $h) }}" class="inline">
                    @csrf
                    <button class="btn btn-solid-yellow">Resume</button>
                </form>
                <form method="POST" action="{{ route('pos.cancel', $h) }}" class="inline confirm-submit" data-confirm-message="Move this held order to Trash? You can restore it later from Trash.">
                    @csrf
                    <button class="btn btn-red">Delete</button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="6">No held orders.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $heldOrders->links() }}</div>
</div>
@endsection
