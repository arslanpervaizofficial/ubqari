@extends('layouts.app')
@section('title', 'Customer Ledger')
@section('content')
<h1 class="text-2xl font-bold mb-1">{{ $customer->name }} — Ledger</h1>
<p class="text-gray-600 mb-4">Credit balance owed: <span class="font-bold {{ $customer->credit_balance>0?'text-red-600':'' }}">{{ number_format($customer->credit_balance,2) }}</span></p>

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
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Amount</th><th class="p-3">Note</th><th class="p-3">Recorded By</th></tr></thead>
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
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endif
@endsection
