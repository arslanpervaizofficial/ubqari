@extends('layouts.app')
@section('title', 'Stock Report')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Stock Report</h1>
    <a href="{{ route('reports.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back to Reports</a>
</div>
<form method="GET" data-ajax-filter="stock-report" class="mb-4">
    <input type="hidden" name="filter" value="{{ $filter }}">
    <input type="text" id="stock-search-input" name="search" value="{{ request('search') }}" placeholder="Search product..." class="border rounded px-3 py-2 w-64">
    <button class="btn btn-gray">Search</button>
</form>

<div data-ajax-list="stock-report">
<div class="bg-white p-5 rounded-xl shadow-sm mb-4">
    <div class="text-gray-500 text-sm">Total Stock Value (at purchase price)</div>
    <div class="text-2xl font-bold" id="total-stock-value">{{ number_format($totalStockValue, 2) }}</div>
</div>
<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
    <div class="flex gap-2">
        <a href="{{ route('reports.stock', ['search' => request('search')]) }}" class="btn {{ $filter === 'all' ? 'btn-dark' : 'btn-gray' }}" data-filter-label="All Products">All ({{ $outOfStockCount + $availableCount }})</a>
        <a href="{{ route('reports.stock', ['filter' => 'available', 'search' => request('search')]) }}" class="btn {{ $filter === 'available' ? 'btn-dark' : 'btn-gray' }}" data-filter-label="Stock Available">Stock Available ({{ $availableCount }})</a>
        <a href="{{ route('reports.stock', ['filter' => 'out', 'search' => request('search')]) }}" class="btn {{ $filter === 'out' ? 'btn-dark' : 'btn-gray' }}" data-filter-label="Out of Stock">Out of Stock ({{ $outOfStockCount }})</a>
    </div>
    <button type="button" id="download-stock-pdf" class="btn btn-solid-green">
        <svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Download PDF
    </button>
</div>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm" id="stock-report-table">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">Product</th><th class="p-3">Category</th><th class="p-3">Stock</th><th class="p-3">Stock Value</th><th class="p-3">Status</th></tr></thead>
    <tbody>
    @foreach($products as $p)
        <tr class="border-t">
            <td class="p-3">{{ $p->name }}</td>
            <td class="p-3">{{ $p->category }}</td>
            <td class="p-3">{{ $p->stock }} {{ $p->unit }}</td>
            <td class="p-3">{{ number_format($p->stock * $p->purchase_price, 2) }}</td>
            <td class="p-3">
                @if($p->stock <= 0)
                    <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">Out of Stock</span>
                @elseif($p->isLowStock())
                    <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Low Stock</span>
                @else
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">OK</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script>
// The button lives INSIDE [data-ajax-list="stock-report"] (next to the
// filter tabs, where it visually belongs), but that whole container's
// content gets replaced via container.innerHTML = ... on every
// filter/search (see app.blade.php's runAjaxFilter) — and HTML inserted
// that way never re-executes any <script> tag that came with it, so a
// listener attached directly to the button would only work once and then
// go silently dead after the first search.
//
// Fix: attach ONE delegated listener to `document` (which is never
// replaced) instead of to the button itself. Since the button keeps the
// same id every time it's re-rendered, e.target.closest() still finds it
// after any number of AJAX refreshes. Everything the PDF needs (filter
// label, search term, table rows, total value) is read live from the DOM
// at click time, so it's always accurate no matter how the current state
// was reached.
document.addEventListener('click', function (e) {
    const btn = e.target.closest('#download-stock-pdf');
    if (!btn) return;

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt' });

    const activeTab = document.querySelector('[data-ajax-list="stock-report"] .btn-dark[data-filter-label]');
    const filterLabel = activeTab ? activeTab.dataset.filterLabel : 'All Products';
    const searchTerm = document.getElementById('stock-search-input')?.value?.trim() || '';
    const generatedAt = new Date().toLocaleString();

    doc.setFontSize(16);
    doc.text('Stock Report', 40, 40);
    doc.setFontSize(10);
    doc.setTextColor(100);
    doc.text(`Filter: ${filterLabel}${searchTerm ? ' — Search: "' + searchTerm + '"' : ''}`, 40, 58);
    doc.text(`Generated: ${generatedAt}`, 40, 72);

    const rows = Array.from(document.querySelectorAll('#stock-report-table tbody tr')).map(tr => {
        const cells = tr.querySelectorAll('td');
        return [
            cells[0].innerText.trim(),
            cells[1].innerText.trim(),
            cells[2].innerText.trim(),
            cells[3].innerText.trim(),
            cells[4].innerText.trim(),
        ];
    });

    doc.autoTable({
        startY: 88,
        head: [['Product', 'Category', 'Stock', 'Stock Value', 'Status']],
        body: rows,
        styles: { fontSize: 8, cellPadding: 4 },
        headStyles: { fillColor: [30, 41, 59] },
        didDrawPage: function () {
            doc.setFontSize(8);
            doc.setTextColor(150);
            doc.text(`Page ${doc.internal.getNumberOfPages()}`, doc.internal.pageSize.getWidth() - 60, doc.internal.pageSize.getHeight() - 20);
        },
    });

    const totalValue = document.getElementById('total-stock-value')?.innerText?.trim() || '0.00';
    const finalY = doc.lastAutoTable.finalY + 20;
    doc.setFontSize(11);
    doc.setTextColor(0);
    doc.setFont(undefined, 'bold');
    doc.text(`Total Stock Value (at purchase price): ${totalValue}`, 40, finalY);

    doc.save(`stock-report-${filterLabel.toLowerCase().replace(/\s+/g, '-')}-${new Date().toISOString().slice(0, 10)}.pdf`);
});
</script>
@endsection
