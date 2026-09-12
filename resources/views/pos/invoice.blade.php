@extends('layouts.app')
@section('title', 'Invoice ' . $order->order_number)
@section('content')
<style>
    /* Default (A4/letter) print styling */
    @media print {
        #invoice-card { box-shadow: none !important; border: none !important; }
    }
    /* Thermal (80mm receipt) mode — toggled via a body class right before printing */
    @media print {
        body.thermal-print #invoice-card {
            max-width: 80mm !important;
            width: 80mm !important;
            font-size: 11px !important;
            padding: 6px !important;
        }
        body.thermal-print #invoice-card h1 { font-size: 14px !important; }
        body.thermal-print #invoice-card table { font-size: 10px !important; }
        body.thermal-print #invoice-card .invoice-header { flex-direction: column !important; }
        @page { size: auto; margin: 2mm; }
    }
</style>

<div id="invoice-card" class="max-w-2xl mx-auto bg-white p-6 rounded-xl shadow-sm">
    <div class="invoice-header flex justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold">{{ \App\Models\AppSetting::current()->print_title }}</h1>
            <p class="text-sm text-gray-500">{{ $order->is_quotation ? 'QUOTATION / ESTIMATE' : 'INVOICE' }}</p>
        </div>
        <div class="text-right text-sm">
            <div>Order #: {{ $order->order_number }}</div>
            <div>Date: {{ $order->created_at->format('Y-m-d H:i') }}</div>
            <div>Cashier: {{ $order->cashier->name }}</div>
        </div>
    </div>

    <p class="mb-2 text-sm">Customer: {{ $order->customer->name ?? 'Walk-in' }}</p>

    <table class="w-full text-sm mb-4">
        <thead class="border-b text-left"><tr><th class="py-1">Item</th><th>Qty</th><th>Price</th><th>Disc</th><th>Total</th></tr></thead>
        <tbody>
        @foreach($order->items as $item)
            <tr class="border-b">
                <td class="py-1">{{ $item->product->name }}</td>
                <td>{{ $item->quantity }} {{ $item->product->unit }}</td>
                <td>{{ number_format($item->unit_price,2) }}</td>
                <td>{{ $item->discount_percent }}%</td>
                <td>{{ number_format($item->line_total,2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="text-right text-sm space-y-1">
        <div>Subtotal: {{ number_format($order->subtotal, 2) }}</div>
        <div>Item Discounts: -{{ number_format($order->line_discount_total, 2) }}</div>
        <div>Overall Discount ({{ $order->discount_percent }}%): -{{ number_format($order->discount_amount, 2) }}</div>
        <div class="font-bold text-lg">Total: {{ number_format($order->total, 2) }}</div>
        @if(!$order->is_quotation)
        <div>Paid: {{ number_format($order->paid_amount, 2) }} ({{ $order->payment_method }})</div>
        @if($order->bank_name || $order->transaction_id)
            <div class="text-gray-500">{{ $order->bank_name }} @if($order->transaction_id) · Txn: {{ $order->transaction_id }} @endif</div>
        @endif
        <div>Due: {{ number_format($order->due_amount, 2) }}</div>
        @endif
    </div>

    <div class="mt-6 flex gap-2 print:hidden">
        <button id="print-a4-btn" class="bg-gray-900 text-white px-4 py-2 rounded">Print (A4)</button>
        <button id="print-thermal-btn" class="bg-gray-700 text-white px-4 py-2 rounded">Print (Thermal)</button>
        <a href="{{ route('pos.index') }}" class="bg-gray-200 px-4 py-2 rounded">Back to Billing</a>
        @if($order->status === 'completed')
        <form method="POST" action="{{ route('pos.reorder', $order) }}">
            @csrf
            <button class="bg-blue-600 text-white px-4 py-2 rounded">Quick Re-order</button>
        </form>
        @endif
    </div>
</div>

<script>
document.getElementById('print-a4-btn').addEventListener('click', function () {
    document.body.classList.remove('thermal-print');
    window.print();
});
document.getElementById('print-thermal-btn').addEventListener('click', function () {
    document.body.classList.add('thermal-print');
    window.print();
});
window.addEventListener('afterprint', function () {
    document.body.classList.remove('thermal-print');
});
</script>
@endsection
