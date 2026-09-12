@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<h1 class="text-2xl font-bold mb-6 text-gray-800">Dashboard</h1>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-blue-500">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-gray-500 text-xs uppercase tracking-wide font-semibold">Today's Sales</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">Rs {{ number_format($todaySales, 0) }}</div>
            </div>
            <div class="bg-blue-100 text-blue-600 rounded-full w-14 h-14 flex items-center justify-center flex-shrink-0">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m3.5-9.5c0-1.38-1.567-2.5-3.5-2.5s-3.5 1.12-3.5 2.5c0 1.38 1.567 2.5 3.5 2.5s3.5 1.12 3.5 2.5-1.567 2.5-3.5 2.5-3.5-1.12-3.5-2.5"/></svg>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-purple-500">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-gray-500 text-xs uppercase tracking-wide font-semibold">This Month</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">Rs {{ number_format($monthSales, 0) }}</div>
            </div>
            <div class="bg-purple-100 text-purple-600 rounded-full w-14 h-14 flex items-center justify-center flex-shrink-0">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13h4v8H3v-8zM10 3h4v18h-4V3zM17 8h4v13h-4V8z"/></svg>
            </div>
        </div>
    </div>
    <a href="{{ route('pos.held-index') }}" class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-yellow-500 hover:shadow-md transition cursor-pointer">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-gray-500 text-xs uppercase tracking-wide font-semibold">Held Orders</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ $heldOrdersCount }}</div>
            </div>
            <div class="bg-yellow-100 text-yellow-600 rounded-full w-14 h-14 flex items-center justify-center flex-shrink-0">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
            </div>
        </div>
        @if($heldOrdersCount)<div class="text-xs text-yellow-600 mt-2">Click to view held orders →</div>@endif
    </a>
    <a href="#" id="oos-card-trigger" class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-red-500 hover:shadow-md transition cursor-pointer">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-gray-500 text-xs uppercase tracking-wide font-semibold">Out of Stock</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ $outOfStock->count() }}</div>
            </div>
            <div class="bg-red-100 text-red-600 rounded-full w-14 h-14 flex items-center justify-center flex-shrink-0">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
            </div>
        </div>
        @if($outOfStock->count())<div class="text-xs text-red-500 mt-2">Click to create a restock order →</div>@endif
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm p-5">
        <h2 class="font-semibold text-gray-700 mb-3">Sales — Last 7 Days</h2>
        <canvas id="salesChart" height="90"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5">
        <h2 class="font-semibold text-gray-700 mb-3">Overview</h2>
        <div class="space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Total Products</span><span class="font-semibold">{{ $totalProducts }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Total Customers</span><span class="font-semibold">{{ $totalCustomers }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Low Stock Items</span><span class="font-semibold text-yellow-600">{{ $lowStock->count() }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Out of Stock</span><span class="font-semibold text-red-600">{{ $outOfStock->count() }}</span></div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5">
        <h2 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Top Parties (Customers)
        </h2>
        @if($topParties->count())
            <div class="space-y-2">
                @foreach($topParties as $c)
                    <div class="flex items-center justify-between text-sm py-1 border-b last:border-0">
                        <div class="flex items-center gap-2">
                            <img src="{{ $c->image_url }}" class="w-7 h-7 rounded-full object-cover">
                            <span>{{ $c->name }}</span>
                        </div>
                        <span class="font-semibold">Rs {{ number_format($c->total_purchased, 0) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-400 text-sm">No completed sales yet.</p>
        @endif
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5">
        <h2 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/></svg>
            Top Products
        </h2>
        @if($topProducts->count())
            <canvas id="topProductsChart" height="140"></canvas>
        @else
            <p class="text-gray-400 text-sm">No completed sales yet.</p>
        @endif
    </div>
</div>

@if($lowStock->count())
<div class="bg-white rounded-xl shadow-sm p-5 mb-6 border-l-4 border-yellow-400">
    <h2 class="font-semibold mb-3 text-yellow-700 flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 4.5c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
        Low Stock Alert
        @if(in_array(auth()->user()->role, ['admin', 'manager']))
            — click an item to create a restock order
        @endif
    </h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
        @foreach($lowStock as $p)
            @if(in_array(auth()->user()->role, ['admin', 'manager']))
            <a href="{{ route('purchase-orders.create', ['product_id' => $p->id, 'suggested_qty' => $p->suggested_qty]) }}"
               class="text-sm bg-yellow-50 hover:bg-yellow-100 transition rounded-lg px-3 py-2 flex justify-between">
                <span>{{ $p->name }}</span>
                <span class="font-semibold">{{ $p->stock }} {{ $p->unit }}</span>
            </a>
            @else
            <div class="text-sm bg-yellow-50 rounded-lg px-3 py-2 flex justify-between">
                <span>{{ $p->name }}</span>
                <span class="font-semibold">{{ $p->stock }} {{ $p->unit }}</span>
            </div>
            @endif
        @endforeach
    </div>
    @unless(in_array(auth()->user()->role, ['admin', 'manager']))
        <p class="text-xs text-gray-400 mt-2">Ask an Admin or Manager to place a restock order for these.</p>
    @endunless
</div>
@endif

<!-- Out-of-stock modal: bulk-select quantities, then create ONE purchase order estimate.
     Admin/Manager only — a Cashier can't create Purchase Orders, so there's
     nothing for them to do with this popup; showing it would just be a
     dead end (and the exact 403 this update's error page is fixing). -->
@if(in_array(auth()->user()->role, ['admin', 'manager']))
<div id="oos-modal-overlay" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full p-6">
        <h3 class="font-bold text-lg text-red-700 mb-1">⚠ Out of Stock</h3>
        <p class="text-sm text-gray-500 mb-4">{{ $outOfStock->count() }} product(s) are at zero stock. Set quantities below and create one restock order for all of them.</p>
        <form method="GET" action="{{ route('purchase-orders.create') }}">
            <div class="space-y-2 max-h-72 overflow-y-auto mb-4">
                @foreach($outOfStock as $p)
                    <div class="flex items-center justify-between gap-3 text-sm bg-red-50 rounded-lg px-3 py-2">
                        <label class="flex items-center gap-2 flex-1">
                            <input type="checkbox" checked class="oos-check" data-target="oos-row-{{ $p->id }}">
                            <span>{{ $p->name }}</span>
                        </label>
                        <input type="hidden" name="product_id[]" value="{{ $p->id }}" id="oos-row-{{ $p->id }}-pid">
                        <input type="number" name="qty[]" value="{{ $p->suggested_qty }}" min="1" step="1"
                               class="w-20 border rounded px-2 py-1" id="oos-row-{{ $p->id }}-qty">
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between">
                <button type="button" id="oos-modal-dismiss" class="btn btn-gray">Dismiss for today</button>
                <button class="btn btn-solid-blue">Create Purchase Order →</button>
            </div>
        </form>
    </div>
</div>
@endif

<script>
function openOosModal() { document.getElementById('oos-modal-overlay')?.classList.remove('hidden'); }
document.getElementById('oos-card-trigger')?.addEventListener('click', function (e) { e.preventDefault(); openOosModal(); });

document.getElementById('oos-modal-dismiss')?.addEventListener('click', function () {
    const todayKey = 'oosAlertShown_' + new Date().toISOString().slice(0, 10);
    localStorage.setItem(todayKey, '1');
    document.getElementById('oos-modal-overlay').classList.add('hidden');
});

document.querySelectorAll('.oos-check').forEach(cb => {
    cb.addEventListener('change', function () {
        const base = this.dataset.target;
        const pidField = document.getElementById(base + '-pid');
        const qtyField = document.getElementById(base + '-qty');
        pidField.disabled = !this.checked;
        qtyField.disabled = !this.checked;
    });
});

@if($outOfStock->count() && in_array(auth()->user()->role, ['admin', 'manager']))
(function () {
    const todayKey = 'oosAlertShown_' + new Date().toISOString().slice(0, 10);
    if (!localStorage.getItem(todayKey)) {
        openOosModal();
    }
})();
@endif

new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: {!! json_encode($last7Days->pluck('label')) !!},
        datasets: [{
            label: 'Sales',
            data: {!! json_encode($last7Days->pluck('total')) !!},
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.1)',
            tension: 0.35,
            fill: true,
            pointRadius: 4,
        }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

@if($topProducts->count())
new Chart(document.getElementById('topProductsChart'), {
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
