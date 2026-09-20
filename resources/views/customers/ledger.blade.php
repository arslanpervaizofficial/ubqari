@extends('layouts.app')
@section('title', 'Customer Ledger')
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3 mb-1">
    <h1 class="text-2xl font-bold">{{ $customer->name }} — Ledger</h1>
    <div class="flex gap-2 print:hidden">
        <button type="button" id="ledger-export-pdf-btn" class="btn btn-gray">
            <svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M13 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V9l-6-6z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v5a1 1 0 001 1h5"/>
            </svg>
            Export PDF
        </button>
        <button type="button" id="ledger-whatsapp-btn" class="btn btn-solid-green">
            <svg class="w-4 h-4 inline -mt-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.149-.15.35-.372.523-.558.174-.185.223-.309.35-.526.148-.236.075-.443-.024-.606-.075-.15-.673-1.62-.923-2.228-.24-.57-.483-.5-.673-.51h-.573c-.198 0-.52.074-.792.372-.273.297-1.04 1.017-1.04 2.479 0 1.462 1.065 2.875 1.213 3.075.149.198 2.06 3.16 5.058 4.437 2.996 1.276 2.996.85 3.535.795.537-.05 1.758-.716 2.006-1.412.248-.694.248-1.29.174-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 1.87.505 3.62 1.386 5.126L2 22l4.994-1.31A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18.15a8.14 8.14 0 01-4.16-1.14l-.298-.177-3.098.813.827-3.023-.194-.31A8.15 8.15 0 013.85 12c0-4.5 3.65-8.15 8.15-8.15S20.15 7.5 20.15 12 16.5 20.15 12 20.15z"/></svg>
            Share on WhatsApp
        </button>
    </div>
</div>

<div id="ledger-print-area">
    <div class="hidden ledger-print-only mb-3">
        <div class="text-lg font-bold">{{ \App\Models\AppSetting::current()->print_title }}</div>
        <div class="text-sm text-gray-500">Ledger Statement — {{ $customer->name }} ({{ $customer->phone }})</div>
        <div class="text-xs text-gray-400">Generated: {{ now()->format('Y-m-d H:i') }}</div>
    </div>

    <p class="text-gray-600 mb-4">
        @if($customer->credit_balance > 0)
            Balance owed by customer: <span class="font-bold text-red-600">{{ number_format($customer->credit_balance, 2) }}</span>
        @elseif($customer->credit_balance < 0)
            Advance/credit owed TO customer: <span class="font-bold text-blue-700">{{ number_format(abs($customer->credit_balance), 2) }}</span>
        @else
            Balance: <span class="font-bold">0.00</span> — settled up
        @endif
    </p>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm p-5">
            <h2 class="font-semibold text-gray-700 mb-3">Monthly Orders — Last 12 Months</h2>
            @if($monthlyOrders->sum('count'))
                <canvas id="monthlyOrdersChart" height="90"></canvas>
            @else
                <p class="text-gray-400 text-sm">No completed orders yet for this customer.</p>
            @endif
        </div>
        <div class="bg-white rounded-xl shadow-sm p-5">
            <h2 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/></svg>
                Top Products (This Customer)
            </h2>
            @if($topProducts->count())
                <canvas id="customerTopProductsChart" height="140"></canvas>
            @else
                <p class="text-gray-400 text-sm">No completed orders yet.</p>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-5 mb-6">
        <h2 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Products Not Ordered In A While
        </h2>
        @if($lapsedProducts->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                @foreach($lapsedProducts as $row)
                    <div class="text-sm rounded-lg px-3 py-2 flex justify-between items-center {{ $row->days_since >= 60 ? 'bg-red-50' : ($row->days_since >= 30 ? 'bg-yellow-50' : 'bg-gray-50') }}">
                        <span>{{ $row->product->name ?? '—' }}</span>
                        <span class="font-semibold {{ $row->days_since >= 60 ? 'text-red-600' : ($row->days_since >= 30 ? 'text-yellow-700' : 'text-gray-500') }}">{{ $row->days_since }}d ago</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-400 text-sm">This customer has no order history yet.</p>
        @endif
    </div>

    <!-- Orders — paginated version for on-screen browsing (excluded from export/print) -->
    <div class="ledger-screen-only" data-ajax-list="ledger">
    <div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
    <h2 class="font-semibold p-4 border-b">Order History</h2>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="p-3">Order #</th><th class="p-3">Date</th><th class="p-3">Total</th><th class="p-3">Paid</th><th class="p-3">Due</th><th class="p-3">Status</th></tr></thead>
        <tbody>
        @forelse($orders as $o)
            <tr class="border-t">
                <td class="p-3">{{ $o->order_number }}</td>
                <td class="p-3">{{ $o->created_at->format('Y-m-d') }}</td>
                <td class="p-3">{{ number_format($o->total,2) }}</td>
                <td class="p-3">{{ number_format($o->paid_amount,2) }}</td>
                <td class="p-3">{{ number_format($o->due_amount,2) }}</td>
                <td class="p-3">{{ $o->status }}</td>
            </tr>
        @empty
            <tr><td class="p-3 text-gray-400" colspan="6">No orders yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
    </div>

    <!-- Orders — full, unpaginated version, ONLY for export/print (kept hidden otherwise) -->
    <div class="hidden ledger-print-only bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
    <h2 class="font-semibold p-4 border-b">Order History (Complete)</h2>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="p-3">Order #</th><th class="p-3">Date</th><th class="p-3">Total</th><th class="p-3">Paid</th><th class="p-3">Due</th><th class="p-3">Status</th></tr></thead>
        <tbody>
        @forelse($allOrders as $o)
            <tr class="border-t">
                <td class="p-3">{{ $o->order_number }}</td>
                <td class="p-3">{{ $o->created_at->format('Y-m-d') }}</td>
                <td class="p-3">{{ number_format($o->total,2) }}</td>
                <td class="p-3">{{ number_format($o->paid_amount,2) }}</td>
                <td class="p-3">{{ number_format($o->due_amount,2) }}</td>
                <td class="p-3">{{ $o->status }}</td>
            </tr>
        @empty
            <tr><td class="p-3 text-gray-400" colspan="6">No orders yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>

    @if($payments->count())
    <!-- Credit/Debit — on-screen version with Edit/Delete (excluded from export/print) -->
    <div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6 ledger-screen-only">
    <h2 class="font-semibold p-4 border-b">Credit / Debit History</h2>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Amount</th><th class="p-3">Note</th><th class="p-3">Recorded By</th><th class="p-3">Actions</th></tr></thead>
        <tbody>
        @foreach($payments as $pmt)
            <tr class="border-t">
                <td class="p-3">{{ $pmt->created_at->format('Y-m-d H:i') }}</td>
                <td class="p-3">
                    @if($pmt->type === 'credit')
                        <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Credit</span>
                    @else
                        <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">Debit</span>
                    @endif
                </td>
                <td class="p-3 font-semibold {{ $pmt->type === 'credit' ? 'text-green-700' : 'text-red-700' }}">
                    {{ $pmt->type === 'credit' ? '-' : '+' }}{{ number_format($pmt->amount, 2) }}
                </td>
                <td class="p-3">{{ $pmt->note }}</td>
                <td class="p-3">{{ $pmt->user->name ?? '—' }}</td>
                <td class="p-3 space-x-1 whitespace-nowrap">
                    <button type="button" class="btn btn-blue open-edit-payment-modal"
                            data-action="{{ route('customers.update-payment', [$customer, $pmt]) }}"
                            data-type="{{ $pmt->type }}" data-amount="{{ $pmt->amount }}" data-note="{{ $pmt->note }}">
                        Edit
                    </button>
                    @if(auth()->user()->role === 'admin')
                    <form method="POST" action="{{ route('customers.destroy-payment', [$customer, $pmt]) }}" class="inline confirm-submit" data-confirm-message="Move this ledger entry to Trash? The customer's balance will be adjusted back. You can restore it anytime from Trash.">
                        @csrf @method('DELETE')
                        <button class="btn btn-red">Delete</button>
                    </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <!-- Credit/Debit — clean read-only version, ONLY for export/print -->
    <div class="hidden ledger-print-only bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
    <h2 class="font-semibold p-4 border-b">Credit / Debit History</h2>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Amount</th><th class="p-3">Note</th></tr></thead>
        <tbody>
        @foreach($payments as $pmt)
            <tr class="border-t">
                <td class="p-3">{{ $pmt->created_at->format('Y-m-d H:i') }}</td>
                <td class="p-3">{{ $pmt->type === 'credit' ? 'Credit' : 'Debit' }}</td>
                <td class="p-3 font-semibold">{{ $pmt->type === 'credit' ? '-' : '+' }}{{ number_format($pmt->amount, 2) }}</td>
                <td class="p-3">{{ $pmt->note }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @endif

    <!-- Statement summary — always shown, at the very end -->
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 {{ $customer->credit_balance > 0 ? 'border-red-500' : ($customer->credit_balance < 0 ? 'border-blue-500' : 'border-green-500') }}">
        <h2 class="font-semibold text-gray-700 mb-3">Statement Summary</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm mb-3">
            <div><div class="text-gray-500">Total Billed</div><div class="font-semibold">{{ number_format($ledgerSummary['total_billed'], 2) }}</div></div>
            <div><div class="text-gray-500">Total Paid</div><div class="font-semibold text-green-700">{{ number_format($ledgerSummary['total_paid'], 2) }}</div></div>
            <div><div class="text-gray-500">Due (from orders)</div><div class="font-semibold text-red-600">{{ number_format($ledgerSummary['total_due_from_orders'], 2) }}</div></div>
            <div><div class="text-gray-500">Credit/Debit Adjustments</div><div class="font-semibold">{{ number_format($payments->sum(fn($p) => $p->type === 'credit' ? -$p->amount : $p->amount), 2) }}</div></div>
        </div>
        <div class="border-t pt-3 text-lg">
            @if($customer->credit_balance > 0)
                <span class="font-bold">Total Balance Due: <span class="text-red-600">{{ number_format($customer->credit_balance, 2) }}</span></span>
            @elseif($customer->credit_balance < 0)
                <span class="font-bold">Total Advance/Credit: <span class="text-blue-700">{{ number_format(abs($customer->credit_balance), 2) }}</span></span>
            @else
                <span class="font-bold">Total Balance: <span class="text-green-700">0.00 — Settled Up</span></span>
            @endif
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6 mt-6 print:hidden">
    <h2 class="font-semibold mb-3">Record Credit/Debit (no order involved — payment received, a return, or a manual adjustment)</h2>
    <form method="POST" action="{{ route('customers.record-payment', $customer) }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="flex gap-2">
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-green-500 has-[:checked]:bg-green-50 text-sm">
                <input type="radio" name="type" value="credit" checked> Credit
            </label>
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50 text-sm">
                <input type="radio" name="type" value="debit"> Debit
            </label>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="border rounded px-3 py-2 w-40">
            @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm text-gray-600 mb-1">Note (optional)</label>
            <input name="note" placeholder="e.g. Cash received / Returned 2x Aag Shifa" class="border rounded px-3 py-2 w-full">
        </div>
        <button class="btn btn-solid-green">Save</button>
    </form>
</div>

<!-- Edit Credit/Debit modal -->
<div id="edit-payment-modal-overlay" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 print:hidden">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6">
        <h3 class="font-bold mb-4">Edit Ledger Entry</h3>
        <form id="edit-payment-modal-form" method="POST" novalidate>
            @csrf @method('PATCH')
            <div class="flex gap-2 mb-3">
                <label class="flex-1 border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-green-500 has-[:checked]:bg-green-50">
                    <input type="radio" name="type" value="credit" id="edit-payment-type-credit"> Credit
                </label>
                <label class="flex-1 border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                    <input type="radio" name="type" value="debit" id="edit-payment-type-debit"> Debit
                </label>
            </div>
            <label class="block text-sm text-gray-600 mb-1">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" id="edit-payment-amount" required data-label="Amount" class="w-full border rounded px-3 py-2 mb-3">
            <label class="block text-sm text-gray-600 mb-1">Note (optional)</label>
            <input name="note" id="edit-payment-note" class="w-full border rounded px-3 py-2 mb-4">
            <div class="flex justify-end gap-2">
                <button type="button" id="edit-payment-modal-cancel" class="btn btn-gray">Cancel</button>
                <button class="btn btn-solid-green">Save</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* Kept outside @media print (unlike the display swap below) because
       html2pdf.js/html2canvas — used by the "Share on WhatsApp" button —
       renders the page in its normal on-screen context, not inside an
       actual @media print context, so a rule scoped only to print would
       be invisible to it. break-inside:avoid has no visible effect on
       screen anyway, so applying it unconditionally is safe and keeps
       both export paths breaking pages in the same places. */
    #ledger-print-area > div {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    @media print {
        .ledger-screen-only { display: none !important; }
        .ledger-print-only { display: block !important; }

        /* Proper breathing room on every edge of the printed/PDF page —
           without this, content can end up flush against the physical
           page edge (e.g. the closing "Total Balance Due" line looking
           cut off at the very bottom), regardless of what margin the
           browser's own print dialog happens to default to. */
        @page { margin: 15mm 12mm; }

        /* A little extra room after the very last block so the closing
           balance line always has clear space below it instead of
           sitting right at the page's bottom margin. */
        #ledger-print-area > div:last-child { margin-bottom: 10mm; }
    }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.open-edit-payment-modal');
    if (!btn) return;
    document.getElementById('edit-payment-modal-form').action = btn.dataset.action;
    document.getElementById('edit-payment-type-credit').checked = btn.dataset.type === 'credit';
    document.getElementById('edit-payment-type-debit').checked = btn.dataset.type === 'debit';
    document.getElementById('edit-payment-amount').value = btn.dataset.amount;
    document.getElementById('edit-payment-note').value = btn.dataset.note || '';
    document.getElementById('edit-payment-modal-overlay').classList.remove('hidden');
});
document.getElementById('edit-payment-modal-cancel')?.addEventListener('click', function () {
    document.getElementById('edit-payment-modal-overlay').classList.add('hidden');
});

// Swaps the on-screen (paginated orders / actions-included payments) view
// for the export view (full order history / clean payments table) —
// shared by BOTH the "Export PDF" button (real browser print, where CSS
// @media print rules above do the same swap automatically) and the
// "Share on WhatsApp" button (html2pdf/html2canvas, which does NOT know
// about @media print, so it needs this same swap done manually via JS
// right before capturing). Keeping this as one shared function is what
// keeps the two exports visually identical, as one and the same file.
function ledgerSetExportView(exporting) {
    document.querySelectorAll('.ledger-screen-only').forEach(el => el.classList.toggle('hidden', exporting));
    document.querySelectorAll('.ledger-print-only').forEach(el => el.classList.toggle('hidden', !exporting));
}

document.getElementById('ledger-export-pdf-btn').addEventListener('click', function () {
    window.print();
});
window.addEventListener('beforeprint', () => ledgerSetExportView(true));
window.addEventListener('afterprint', () => ledgerSetExportView(false));

document.getElementById('ledger-whatsapp-btn').addEventListener('click', async function () {
    const btn = this;
    const originalLabel = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Preparing PDF...';

    const area = document.getElementById('ledger-print-area');
    const filename = {!! json_encode(\Illuminate\Support\Str::slug($customer->name) . '-ledger.pdf') !!};

    ledgerSetExportView(true);
    try {
        // scrollX/scrollY/windowWidth/windowHeight pinned to the export
        // area's own full size, computed AFTER switching to the export
        // view above: without this, html2canvas defaults to the current
        // scroll position and the browser's visible viewport height, so
        // a statement taller than one screen only got the visible part
        // captured — everything below that was silently cut off ("half
        // PDF banti hai"), not a rendering failure.
        const opt = {
            margin: 8,
            filename: filename,
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: {
                scale: 2,
                useCORS: true,
                scrollX: 0,
                scrollY: 0,
                windowWidth: area.scrollWidth,
                windowHeight: area.scrollHeight,
            },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['css', 'avoid-all'] },
        };

        const worker = html2pdf().set(opt).from(area);
        const pdfBlob = await worker.outputPdf('blob');
        const file = new File([pdfBlob], filename, { type: 'application/pdf' });

        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            await navigator.share({
                files: [file],
                title: {!! json_encode($customer->name . ' — Ledger') !!},
                text: {!! json_encode('Ledger statement for ' . $customer->name) !!},
            });
        } else {
            // Desktop / older browsers can't accept a file through a
            // plain wa.me link — that's a WhatsApp/browser limitation,
            // not something a website can work around. Best available
            // fallback: download the exact same PDF, then open WhatsApp
            // with the chat ready so it can be attached in one more tap.
            await html2pdf().set(opt).from(area).save();
            const msg = encodeURIComponent('Ledger statement for ' + {!! json_encode($customer->name) !!} + ' — PDF just downloaded, please attach it here.');
            window.open('https://wa.me/?text=' + msg, '_blank');
        }
    } catch (err) {
        alert('Could not prepare the PDF: ' + err.message);
    } finally {
        ledgerSetExportView(false);
        btn.disabled = false;
        btn.innerHTML = originalLabel;
    }
});

@if($monthlyOrders->sum('count'))
new Chart(document.getElementById('monthlyOrdersChart'), {
    data: {
        labels: {!! json_encode($monthlyOrders->pluck('label')) !!},
        datasets: [
            {
                type: 'bar',
                label: 'Total (Rs)',
                data: {!! json_encode($monthlyOrders->pluck('total')) !!},
                backgroundColor: '#3b82f6',
                borderRadius: 6,
                yAxisID: 'y',
            },
            {
                type: 'line',
                label: 'Orders',
                data: {!! json_encode($monthlyOrders->pluck('count')) !!},
                borderColor: '#c2703d',
                backgroundColor: '#c2703d',
                tension: 0.35,
                yAxisID: 'y1',
            },
        ]
    },
    options: {
        scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Rs' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Orders' }, ticks: { precision: 0 } },
        }
    }
});
@endif

@if($topProducts->count())
new Chart(document.getElementById('customerTopProductsChart'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($topProducts->map(fn($p) => $p->product->name ?? '—')) !!},
        datasets: [{
            label: 'Qty Sold',
            data: {!! json_encode($topProducts->pluck('qty_sold')) !!},
            backgroundColor: '#c2703d',
            borderRadius: 6,
        }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});
@endif
</script>
@endsection
