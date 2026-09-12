@extends('layouts.app')
@section('title', 'Customers')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Wholesale Customers</h1>
    <a href="{{ route('customers.create') }}" class="btn btn-dark">+ Add Customer</a>
</div>
<div data-ajax-list="customers">
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="customers" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="customers"
            data-action-url="{{ route('customers.destroy-selected') }}"
            data-confirm-message="Move the selected customers to Trash?">
        Delete Selected
    </button>
    <button type="button" class="btn btn-red" data-bulk-all-submit
            data-action-url="{{ route('customers.destroy-all') }}"
            data-confirm-message="Move ALL customers on this list to Trash?">
        Delete All
    </button>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="customers"></th>@endif
        <th class="p-3"></th><th class="p-3">Name</th><th class="p-3">Phone</th><th class="p-3">Discount %</th><th class="p-3">Credit Balance</th><th class="p-3">Status</th><th class="p-3">Actions</th>
    </tr></thead>
    <tbody>
    @foreach($customers as $c)
        <tr class="border-t {{ !$c->is_active ? 'opacity-60' : '' }}">
            @if(auth()->user()->role === 'admin')<td class="p-3"><input type="checkbox" data-bulk-item="customers" value="{{ $c->id }}"></td>@endif
            <td class="p-3"><img src="{{ $c->image_url }}" class="w-9 h-9 rounded-full object-cover"></td>
            <td class="p-3 font-medium">{{ $c->name }}</td>
            <td class="p-3">{{ $c->phone }}</td>
            <td class="p-3">{{ $c->discount_percent }}%</td>
            <td class="p-3 {{ $c->credit_balance > 0 ? 'text-red-600 font-semibold' : '' }}">{{ number_format($c->credit_balance, 2) }}</td>
            <td class="p-3">
                <span class="px-2 py-1 rounded text-xs {{ $c->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $c->is_active ? 'Active' : 'Disabled' }}</span>
            </td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <button type="button" class="btn btn-solid-green open-payment-modal"
                        data-customer-id="{{ $c->id }}" data-customer-name="{{ $c->name }}" data-balance="{{ $c->credit_balance }}">
                    + Payment
                </button>
                <a href="{{ route('customers.ledger', $c) }}" class="btn btn-gray">Ledger</a>
                <a href="{{ route('customers.edit', $c) }}" class="btn btn-blue">Edit</a>
                <form method="POST" action="{{ route('customers.toggle-active', $c) }}" class="inline">
                    @csrf
                    <button class="btn {{ $c->is_active ? 'btn-yellow' : 'btn-solid-green' }}">{{ $c->is_active ? 'Disable' : 'Enable' }}</button>
                </form>
                @if(auth()->user()->role === 'admin')
                <form method="POST" action="{{ route('customers.destroy', $c) }}" class="inline confirm-submit" data-confirm-message="Move this customer to Trash? You can restore them anytime from Trash.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
<div class="mt-4">{{ $customers->links() }}</div>
</div>

<!-- Quick credit/debit modal, usable right from the list without opening the full ledger -->
<div id="payment-modal-overlay" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6">
        <h3 class="font-bold mb-1">Record Credit/Debit — <span id="payment-modal-name"></span></h3>
        <p class="text-sm text-gray-500 mb-4">Current balance: <span id="payment-modal-balance" class="font-semibold"></span></p>
        <form id="payment-modal-form" method="POST" novalidate>
            @csrf
            <div class="flex gap-2 mb-3">
                <label class="flex-1 border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-green-500 has-[:checked]:bg-green-50">
                    <input type="radio" name="type" value="credit" checked> Credit (payment / return)
                </label>
                <label class="flex-1 border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                    <input type="radio" name="type" value="debit"> Debit (charge)
                </label>
            </div>
            <label class="block text-sm text-gray-600 mb-1">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="w-full border rounded px-3 py-2 mb-3">
            <label class="block text-sm text-gray-600 mb-1">Note (optional)</label>
            <input name="note" placeholder="e.g. Returned 2x Aag Shifa" class="w-full border rounded px-3 py-2 mb-4">
            <div class="flex justify-end gap-2">
                <button type="button" id="payment-modal-cancel" class="btn btn-gray">Cancel</button>
                <button class="btn btn-solid-green">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.open-payment-modal');
    if (!btn) return;
    document.getElementById('payment-modal-name').textContent = btn.dataset.customerName;
    document.getElementById('payment-modal-balance').textContent = Number(btn.dataset.balance).toFixed(2);
    document.getElementById('payment-modal-form').action = `/customers/${btn.dataset.customerId}/payment`;
    document.getElementById('payment-modal-overlay').classList.remove('hidden');
});
document.getElementById('payment-modal-cancel')?.addEventListener('click', function () {
    document.getElementById('payment-modal-overlay').classList.add('hidden');
});
</script>
@endsection
