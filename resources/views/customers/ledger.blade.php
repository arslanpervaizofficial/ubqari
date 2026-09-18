@extends('layouts.app')
@section('title', 'Customer Ledger')
@section('content')
<h1 class="text-2xl font-bold mb-1">{{ $customer->name }} — Ledger</h1>
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

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
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

<div data-ajax-list="ledger">
<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
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

@if($payments->count())
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
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
@endif

<!-- Edit Credit/Debit modal -->
<div id="edit-payment-modal-overlay" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
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
