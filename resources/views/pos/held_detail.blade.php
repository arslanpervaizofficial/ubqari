@extends('layouts.app')
@section('title', 'Held Order ' . $order->order_number)
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Held Order: {{ $order->order_number }}</h1>
    <a href="{{ route('pos.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back to Billing</a>
</div>

<div class="bg-white rounded-xl shadow-sm p-6 w-full max-w-2xl">
    <p class="text-sm text-gray-500 mb-4">
        Customer: <span class="font-medium text-gray-800">{{ $order->customer->name ?? 'Walk-in' }}</span>
        · Held since {{ $order->updated_at->diffForHumans() }}
    </p>

    <table class="w-full text-sm mb-4">
        <thead class="text-left text-gray-500 border-b">
            <tr><th class="py-1">Product</th><th>Qty</th><th>Price</th><th class="text-right">Line Total</th></tr>
        </thead>
        <tbody>
        @forelse($order->items as $item)
            <tr class="border-t">
                <td class="py-1">{{ $item->product->name }}</td>
                <td>{{ $item->quantity }} {{ $item->product->unit }}</td>
                <td>{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">{{ number_format($item->line_total, 2) }}</td>
            </tr>
        @empty
            <tr><td class="py-2 text-gray-400" colspan="4">This held order has no items.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="text-right text-lg font-bold mb-6">Total: {{ number_format($order->total, 2) }}</div>

    <div class="flex justify-end gap-2">
        <form method="POST" action="{{ route('pos.cancel', $order) }}" class="confirm-submit" data-confirm-message="Delete this held order? This cannot be undone.">
            @csrf
            <button class="btn btn-red">Delete</button>
        </form>
        <form method="POST" action="{{ route('pos.resume', $order) }}">
            @csrf
            <button class="btn btn-solid-yellow">Resume This Order</button>
        </form>
    </div>
</div>
@endsection
