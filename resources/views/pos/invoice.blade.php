@extends('layouts.app')
@section('title', 'Invoice ' . $order->order_number)
@section('content')
<style>
    /* Default (A4/letter) print styling */
    @media print {
        #invoice-card { box-shadow: none !important; border: none !important; }
    }
    /* Thermal (3-inch / 76mm roll) mode — toggled via a body class right
     * before printing. Font sizes bumped up from the previous 8-9px —
     * that's what was making printed receipts blurry/hard to read: a
     * thermal printer's fixed dot pitch (usually 180-203 DPI) renders
     * very small font sizes with too few dots per character to stay
     * crisp, especially for bold text or anything slightly rotated/
     * scaled by the print driver. Dropping the Discount column (see the
     * table below) frees up enough width to raise these sizes and still
     * fit a 72mm-wide receipt without wrapping mid-word. Printable width
     * on a 3" roll is usually a little under the full 76.2mm once the
     * printer's own margins are accounted for, so the content box is
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
            font-size: 12px !important;
            line-height: 1.45 !important;
            padding: 2mm !important;
            font-weight: 500 !important;
        }
        body.thermal-print #invoice-card h1 { font-size: 15px !important; font-weight: 700 !important; }
        body.thermal-print #invoice-card p { font-size: 11px !important; }
        body.thermal-print #invoice-card .invoice-header { flex-direction: column !important; gap: 2px !important; }
        body.thermal-print #invoice-card .invoice-header > div:last-child { text-align: left !important; margin-top: 2px !important; }
        body.thermal-print #invoice-card table { font-size: 11px !important; width: 100% !important; table-layout: fixed !important; word-break: break-word !important; }
        body.thermal-print #invoice-card table th,
        body.thermal-print #invoice-card table td { padding: 2px 3px !important; }
        /* Serial No. column just needs to fit "1", "2", ... "99" — the
           Item Name column (now 2nd, since Discount was dropped and
           Serial No. is 1st) gets the most room; the rest are short
           numbers. */
        body.thermal-print #invoice-card table th:first-child,
        body.thermal-print #invoice-card table td:first-child { width: 8% !important; }
        body.thermal-print #invoice-card table th:nth-child(2),
        body.thermal-print #invoice-card table td:nth-child(2) { width: 38% !important; }
        body.thermal-print #invoice-card .text-lg { font-size: 14px !important; }
        body.thermal-print #invoice-card .space-y-1 > div { margin-bottom: 2px !important; }
        body.thermal-print #invoice-card .text-xs { font-size: 10px !important; }
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
document.getElementById('print-a4-btn').addEventListener('click', function () {
    document.body.classList.remove('thermal-print');
    window.print();
});
document.getElementById('print-thermal-btn').addEventListener('click', function () {
    document.body.classList.add('thermal-print');
    // `@page { size: 76mm auto; }` (in the <style> block above) asks the
    // browser to make the page exactly as tall as the content — but
    // "auto" height on a continuous-roll page size isn't reliably honored
    // by every browser/print driver; some cap it at a fixed maximum, which
    // is exactly what "aik maximum limit tak print ata, baaki blank rehta,
    // extra page use hoti" was: anything past that cap spilled onto a
    // second page, with the rest of the first page sitting empty. Setting
    // an EXPLICIT height computed from the actual content, right before
    // printing, replaces "auto" with a real number the driver can't
    // second-guess. Appended to the end of <body> (after the static
    // <style> block above) so it wins the normal CSS cascade for the same
    // `@page` selector — no special-case override syntax needed.
    const card = document.getElementById('invoice-card');
    const heightMm = Math.ceil(card.scrollHeight * 25.4 / 96) + 6;
    let pageSizeStyle = document.getElementById('thermal-page-size-style');
    if (!pageSizeStyle) {
        pageSizeStyle = document.createElement('style');
        pageSizeStyle.id = 'thermal-page-size-style';
        document.body.appendChild(pageSizeStyle);
    }
    pageSizeStyle.textContent = `@media print { @page { size: 76mm ${heightMm}mm; margin: 0; } }`;
    window.print();
});
window.addEventListener('afterprint', function () {
    document.body.classList.remove('thermal-print');
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
