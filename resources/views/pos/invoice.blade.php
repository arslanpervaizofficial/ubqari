@extends('layouts.app')
@section('title', 'Invoice ' . $order->order_number)
@section('content')
<style>
    @media print {
        #invoice-card { box-shadow: none !important; border: none !important; }
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
        <thead class="border-b text-left"><tr><th class="py-1">#</th><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
        <tbody>
        @foreach($order->items as $item)
            <tr class="border-b">
                <td class="py-1">{{ $loop->iteration }}</td>
                <td class="py-1">{{ $item->product->name }}</td>
                <td>{{ $item->quantity }} {{ $item->product->unit }}</td>
                <td>{{ number_format($item->unit_price,2) }}</td>
                <td>{{ number_format($item->line_total,2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="text-xs text-gray-500 mb-4">Total Items: {{ $order->items->count() }} product(s), {{ $order->items->sum('quantity') }} unit(s)</p>

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
        <div>{{ $order->due_amount < 0 ? 'Advance (this order)' : 'Due (this order)' }}: {{ number_format($order->due_amount, 2) }}</div>
        @php
            // customer->credit_balance already has THIS order's (signed)
            // due_amount folded into it, so subtracting it back out
            // isolates what was owed (or held in advance) beforehand.
            $balanceNow = $order->customer ? (float) $order->customer->credit_balance : 0;
            $previousBal = $order->customer ? round($balanceNow - $order->due_amount, 2) : 0;
        @endphp
        @if($order->customer && abs($previousBal) > 0.004)
        <div class="border-t pt-1 mt-1">{{ $previousBal > 0 ? 'Previous Balance Due' : 'Previous Advance' }}: {{ number_format(abs($previousBal), 2) }}</div>
        @endif
        @if($order->customer && abs($balanceNow) > 0.004 && abs($previousBal) > 0.004)
        <div class="font-bold text-lg {{ $balanceNow > 0 ? 'text-red-600' : 'text-blue-700' }}">{{ $balanceNow > 0 ? 'Total Amount Due Now' : 'Advance/Credit Balance' }}: {{ number_format(abs($balanceNow), 2) }}</div>
        @endif
        @endif
    </div>

    <div class="mt-6 flex flex-wrap gap-2 print:hidden" id="invoice-actions">
        <button id="print-a4-btn" class="bg-gray-900 text-white px-4 py-2 rounded">Print (A4)</button>
        <button id="print-thermal-btn" class="bg-gray-700 text-white px-4 py-2 rounded">Print (Thermal)</button>
        <button id="export-pdf-btn" class="bg-gray-200 px-4 py-2 rounded">
            <svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M13 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V9l-6-6z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v5a1 1 0 001 1h5"/>
            </svg>
            Export PDF
        </button>
        <button id="whatsapp-pdf-btn" class="bg-green-600 text-white px-4 py-2 rounded">
            <svg class="w-4 h-4 inline -mt-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.149-.15.35-.372.523-.558.174-.185.223-.309.35-.526.148-.236.075-.443-.024-.606-.075-.15-.673-1.62-.923-2.228-.24-.57-.483-.5-.673-.51h-.573c-.198 0-.52.074-.792.372-.273.297-1.04 1.017-1.04 2.479 0 1.462 1.065 2.875 1.213 3.075.149.198 2.06 3.16 5.058 4.437 2.996 1.276 2.996.85 3.535.795.537-.05 1.758-.716 2.006-1.412.248-.694.248-1.29.174-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 1.87.505 3.62 1.386 5.126L2 22l4.994-1.31A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18.15a8.14 8.14 0 01-4.16-1.14l-.298-.177-3.098.813.827-3.023-.194-.31A8.15 8.15 0 013.85 12c0-4.5 3.65-8.15 8.15-8.15S20.15 7.5 20.15 12 16.5 20.15 12 20.15z"/></svg>
            WhatsApp PDF
        </button>
        <a href="{{ route('pos.index') }}" class="bg-gray-200 px-4 py-2 rounded">Back to Billing</a>
        @if($order->status === 'completed')
        <form method="POST" action="{{ route('pos.reorder', $order) }}">
            @csrf
            <button class="bg-blue-600 text-white px-4 py-2 rounded">Quick Re-order</button>
        </form>
        @endif
    </div>
</div>


{{-- Thermal receipt: a separate, minimal document printed from a hidden
     iframe (see script below). It does NOT reuse the on-screen card, so the
     app layout/sidebar/A4 table can never shrink the text or add side
     margins. Items are stacked (name on one line, qty x price = total on the
     next) so numbers never wrap on a narrow roll. --}}
<template id="thermal-template">
    @php($cfg = \App\Models\AppSetting::current())
    <div class="r">
        <div class="c title">{{ $cfg->print_title }}</div>
        @if($cfg->print_address)<div class="c hdr">{{ $cfg->print_address }}</div>@endif
        @if($cfg->print_phone)<div class="c hdr">Phone: {{ $cfg->print_phone }}</div>@endif
        <div class="c sub">{{ $order->is_quotation ? 'QUOTATION / ESTIMATE' : 'INVOICE' }}</div>
        <div class="hr"></div>
        <div>Order #: {{ $order->order_number }}</div>
        <div>Customer: {{ $order->customer->name ?? 'Walk-in' }}</div>
        <div>Cashier: {{ $order->cashier->name }}</div>
        <div class="hr"></div>
        @foreach($order->items as $item)
            <div class="item">
                <div class="row"><span class="nm">{{ $item->product->name }}</span><span class="b">PKR {{ number_format($item->line_total, 2) }}</span></div>
                <div class="muted">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') }} x PKR {{ number_format($item->unit_price, 2) }}@if($item->discount_percent > 0) (-{{ rtrim(rtrim(number_format($item->discount_percent, 2, '.', ''), '0'), '.') }}%)@endif</div>
            </div>
        @endforeach
        <div class="hr"></div>
        <div class="row"><span>Subtotal:</span><span>PKR {{ number_format($order->subtotal, 2) }}</span></div>
        <div class="row"><span>Item Discount:</span><span>-PKR {{ number_format($order->line_discount_total, 2) }}</span></div>
        @if($order->discount_amount > 0)
        <div class="row"><span>Discount ({{ rtrim(rtrim(number_format($order->discount_percent, 2, '.', ''), '0'), '.') }}%):</span><span>-PKR {{ number_format($order->discount_amount, 2) }}</span></div>
        @endif
        <div class="row big"><span>Total</span><span>PKR {{ number_format($order->total, 2) }}</span></div>
        @if(!$order->is_quotation)
            <div class="hr"></div>
            <div class="row"><span>Paid ({{ $order->payment_method }}):</span><span>PKR {{ number_format($order->paid_amount, 2) }}</span></div>
            @if($order->bank_name || $order->transaction_id)
                <div class="muted">{{ $order->bank_name }} @if($order->transaction_id) Txn: {{ $order->transaction_id }} @endif</div>
            @endif
            <div class="row"><span>{{ $order->due_amount < 0 ? 'Advance (this order):' : 'Due (this order):' }}</span><span>PKR {{ number_format(abs($order->due_amount), 2) }}</span></div>
            @if($order->customer && abs($previousBal ?? 0) > 0.004)
                <div class="row"><span>{{ $previousBal > 0 ? 'Previous Balance:' : 'Previous Advance:' }}</span><span>PKR {{ number_format(abs($previousBal), 2) }}</span></div>
                <div class="row big"><span>{{ $balanceNow > 0 ? 'Total Due' : 'Advance' }}</span><span>PKR {{ number_format(abs($balanceNow), 2) }}</span></div>
            @endif
        @endif
        <div class="hr"></div>
        <div class="row foot"><span>{{ $order->created_at->format('d.m.Y') }}</span><span>{{ $order->created_at->format('H:i:s') }}</span></div>
        <div class="c foot" style="margin-top:4px">Thank you!</div>
    </div>
</template>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
document.getElementById('print-a4-btn').addEventListener('click', function () {
    window.print();
});

// ---- Thermal print -------------------------------------------------
// Roll width in mm (3-inch rolls: 76-80). Only change this if your paper differs.
const THERMAL_WIDTH_MM = 76;
const THERMAL_FONT_PX = 14;

document.getElementById('print-thermal-btn').addEventListener('click', function () {
    const w = THERMAL_WIDTH_MM, f = THERMAL_FONT_PX;
    const css = `
        @page { size: ${w}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; }
        body { width: ${w}mm; font-family: Arial, Helvetica, sans-serif; font-size: ${f}px;
               font-weight: 600; color: #000; line-height: 1.35; }
        .r { width: 100%; padding: 1.5mm 1.5mm 3mm 1.5mm; }
        .c { text-align: center; }
        .title { font-size: ${f + 6}px; font-weight: 800; line-height: 1.2; }
        .hdr { font-size: ${f}px; font-weight: 500; }
        .sub { font-size: ${f - 2}px; letter-spacing: 1px; margin-top: 2px; }
        .hr { border-top: 2px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; }
        .row span:last-child { text-align: right; white-space: nowrap; }
        .nm { font-weight: 700; overflow-wrap: anywhere; }
        .b { font-weight: 800; }
        .muted { font-size: ${f - 1}px; font-weight: 500; }
        .item { margin-bottom: 5px; break-inside: avoid; }
        .big { font-size: ${f + 4}px; font-weight: 800; margin: 3px 0; }
        .foot { font-size: ${f - 2}px; font-weight: 700; }
    `;
    const html = '<!doctype html><html><head><meta charset="utf-8"><title>' +
        {!! json_encode($order->order_number) !!} + '</title><style>' + css + '</style></head><body>' +
        document.getElementById('thermal-template').innerHTML + '</body></html>';

    // A real (small) window of its own: its @page size/margin:0 are honoured
    // exactly, unlike printing an iframe inside the app page, where the
    // browser's default margins were being added on both sides.
    const win = window.open('', '_blank', 'width=420,height=700');
    if (!win) { alert('Popup blocked — please allow popups for this site and try again.'); return; }
    win.document.open(); win.document.write(html); win.document.close();

    setTimeout(function () {
        const heightMm = Math.ceil(win.document.body.scrollHeight * 25.4 / 96) + 4;
        const st = win.document.createElement('style');
        st.textContent = `@page { size: ${w}mm ${heightMm}mm; margin: 0; }`;
        win.document.head.appendChild(st);
        win.focus();
        win.print();
        win.onafterprint = () => win.close();
    }, 300);
});

const invoiceFilename = {!! json_encode($order->order_number . '.pdf') !!};
function buildInvoicePdfOpt() {
    const card = document.getElementById('invoice-card');
    return {
        margin: 6,
        filename: invoiceFilename,
        image: { type: 'jpeg', quality: 0.95 },
        html2canvas: {
            scale: 2,
            useCORS: true,
            scrollX: 0,
            scrollY: 0,
            // Only height needs overriding, to reach content below the
            // fold on a long invoice (that was the "half PDF" bug) — width
            // is deliberately left at html2canvas's own default (the
            // real window's width). Pinning it to the card's own
            // (narrower) width instead, like an earlier version of this
            // did, forces html2canvas to re-layout the ENTIRE page inside
            // a simulated browser window that narrow — which is exactly
            // what pushed the header's left column (title/logo) out of
            // frame and cropped the item table's leftmost columns in the
            // last export.
            windowHeight: card.scrollHeight,
            // The action buttons (Print/Export/WhatsApp/etc.) sit inside
            // this same card so they lay out correctly on screen, hidden
            // from real printing via `print:hidden` — but html2canvas
            // doesn't run inside an actual print context, so that CSS
            // rule is invisible to it and the buttons were rendering
            // straight into the PDF. Explicitly skip that one element by
            // id instead.
            ignoreElements: (el) => el.id === 'invoice-actions',
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['css', 'avoid-all'] },
    };
}

document.getElementById('export-pdf-btn').addEventListener('click', async function () {
    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Preparing PDF...';
    try {
        await html2pdf().set(buildInvoicePdfOpt()).from(document.getElementById('invoice-card')).save();
    } catch (err) {
        alert('Could not generate PDF: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
});

document.getElementById('whatsapp-pdf-btn').addEventListener('click', async function () {
    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Preparing PDF...';
    try {
        const worker = html2pdf().set(buildInvoicePdfOpt()).from(document.getElementById('invoice-card'));
        const pdfBlob = await worker.outputPdf('blob');
        const file = new File([pdfBlob], invoiceFilename, { type: 'application/pdf' });

        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            await navigator.share({
                files: [file],
                title: {!! json_encode('Invoice ' . $order->order_number) !!},
                text: {!! json_encode('Invoice ' . $order->order_number . ' — ' . ($order->customer->name ?? 'Walk-in')) !!},
            });
        } else {
            // Desktop / unsupported browsers can't accept a file through a
            // plain wa.me link — that's a WhatsApp/browser platform
            // limitation, not something a site can route around. Same PDF
            // either way: it just downloads first, then WhatsApp opens
            // with a message ready so it's one more "attach" tap to send.
            await html2pdf().set(buildInvoicePdfOpt()).from(document.getElementById('invoice-card')).save();
            const msg = encodeURIComponent('Invoice ' + {!! json_encode($order->order_number) !!} + ' — PDF just downloaded, please attach it here.');
            window.open('https://wa.me/?text=' + msg, '_blank');
        }
    } catch (err) {
        alert('Could not prepare the PDF: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
});
</script>
@endsection
