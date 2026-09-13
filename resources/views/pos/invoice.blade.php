@extends('layouts.app')
@section('title', 'Invoice ' . $order->order_number)
@section('content')
<style>
    /* Default (A4/letter) print styling */
    @media print {
        #invoice-card { box-shadow: none !important; border: none !important; }
    }
    /* Thermal (3-inch / 76mm roll) mode — toggled via a body class right
     * before printing. Previous version targeted 80mm at 11px, which is
     * wider than an actual 3" (76.2mm) roll's printable area — that's what
     * was causing the right edge of each line to get cut off. Printable
     * width on a 3" roll is usually a little under the full 76.2mm once
     * the printer's own margins are accounted for, so the content box is
     * kept slightly narrower (72mm) than the physical page (76mm) as a
     * safety margin, and the @page size is set explicitly so the browser
     * doesn't fall back to a default (e.g. A4) page and scale/clip the
     * content against it. */
    @media print {
        @page { size: 76mm auto; margin: 0; }
        body.thermal-print { margin: 0; }
        body.thermal-print #invoice-card {
            box-sizing: border-box !important;
            max-width: 72mm !important;
            width: 72mm !important;
            margin: 0 auto !important;
            font-size: 9px !important;
            line-height: 1.35 !important;
            padding: 2mm !important;
        }
        body.thermal-print #invoice-card h1 { font-size: 12px !important; }
        body.thermal-print #invoice-card p { font-size: 9px !important; }
        body.thermal-print #invoice-card .invoice-header { flex-direction: column !important; gap: 2px !important; }
        body.thermal-print #invoice-card .invoice-header > div:last-child { text-align: left !important; margin-top: 2px !important; }
        body.thermal-print #invoice-card table { font-size: 8px !important; width: 100% !important; table-layout: fixed !important; word-break: break-word !important; }
        body.thermal-print #invoice-card table th,
        body.thermal-print #invoice-card table td { padding: 1px 2px !important; }
        /* Item name column needs the most room; the rest are short numbers */
        body.thermal-print #invoice-card table th:first-child,
        body.thermal-print #invoice-card table td:first-child { width: 34% !important; }
        body.thermal-print #invoice-card .text-lg { font-size: 11px !important; }
        body.thermal-print #invoice-card .space-y-1 > div { margin-bottom: 1px !important; }
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
        <div>Due (this order): {{ number_format($order->due_amount, 2) }}</div>
        @php
            // customer->credit_balance already has THIS order's due_amount
            // folded into it (added at checkout), so subtracting it back
            // out isolates whatever was still owed from earlier, separate
            // orders — the two are shown separately, then summed, so the
            // customer sees exactly what they now owe in total, not just
            // what this one transaction added.
            $previousDue = $order->customer ? max(0, $order->customer->credit_balance - $order->due_amount) : 0;
        @endphp
        @if($order->customer && $previousDue > 0)
        <div class="border-t pt-1 mt-1">Previous Balance Due: {{ number_format($previousDue, 2) }}</div>
        <div class="font-bold text-lg text-red-600">Total Amount Due Now: {{ number_format($order->due_amount + $previousDue, 2) }}</div>
        @endif
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
