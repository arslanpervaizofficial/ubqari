@extends('layouts.app')
@section('title', $supplier->name . ' — Supplier Detail')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ $supplier->name }} {{ __('— Supplier Detail') }}</h1>
    <a href="{{ route('suppliers.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back to Suppliers') }}</a>
</div>

<p class="text-gray-600 mb-4">{{ $supplier->phone }} @if($supplier->email) · {{ $supplier->email }} @endif</p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Received PO Total') }}</div>
        <div class="text-2xl font-bold">{{ number_format($dues['received_total'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Value of purchase orders actually received from this supplier') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Pending PO Total (not yet received)') }}</div>
        <div class="text-2xl font-bold">{{ number_format($dues['pending_total'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Ordered but not received — not part of what is owed yet') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Due (balance)') }}</div>
        <div class="text-2xl font-bold {{ $dues['due'] > 0 ? 'text-red-600' : '' }}">{{ number_format($dues['due'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Received') }} {{ number_format($dues['received_total'], 2) }} {{ __('− Payments') }} {{ number_format($dues['payments_total'] ?? 0, 2) }} {{ __('− Credits') }} {{ number_format($dues['credits'] ?? 0, 2) }} + {{ __('Debits') }} {{ number_format($dues['debits'] ?? 0, 2) }}</div>
    </div>
</div>

<p class="text-xs text-gray-400 mb-6">{{ __('A positive Due means we owe the supplier; a negative Due means we have paid them in advance. Supplier returns are already reflected in the Received total (a return reduces the PO total), so they are not subtracted again here.') }}</p>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
<h2 class="font-semibold mb-3">{{ __('Record a supplier ledger entry') }}</h2>
<form method="POST" action="{{ route('suppliers.payments.store', $supplier) }}" class="flex flex-wrap items-end gap-3">
    @csrf
    <div>
        <label class="block text-sm text-gray-600 mb-1">{{ __('Type') }}</label>
        <select name="type" class="border rounded px-3 py-2" required>
            <option value="payment">{{ __('Payment (paid to supplier — Due goes down)') }}</option>
            <option value="credit">{{ __('Credit note (supplier discount — Due goes down)') }}</option>
            <option value="debit">{{ __('Debit (extra charge — Due goes up)') }}</option>
        </select>
        @error('type') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm text-gray-600 mb-1">{{ __('Amount') }}</label>
        <input name="amount" type="number" step="0.01" min="0.01" class="border rounded px-3 py-2 w-40" required>
        @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex-1 min-w-[200px]">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Note') }}</label>
        <input name="note" placeholder="{{ __('e.g. Bank transfer for PO #12') }}" class="border rounded px-3 py-2 w-full">
        @error('note') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <button class="btn btn-solid-green">{{ __('Save') }}</button>
</form>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
<h2 class="font-semibold p-4 border-b">{{ __('Received Purchase Orders') }}</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('PO #') }}</th><th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Total') }}</th></tr></thead>
    <tbody>
    @forelse($receivedOrders as $po)
        <tr class="border-t">
            <td class="p-3">{{ $po->id }}</td>
            <td class="p-3">{{ $po->created_at->format('Y-m-d') }}</td>
            <td class="p-3 font-semibold">{{ number_format($po->total, 2) }}</td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="3">{{ __('No received purchase orders yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4 mb-6">{{ $receivedOrders->links() }}</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
<h2 class="font-semibold p-4 border-b">{{ __('Payment / Credit / Debit History') }}</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Type') }}</th><th class="p-3">{{ __('Amount') }}</th><th class="p-3">{{ __('Note') }}</th><th class="p-3">{{ __('By') }}</th><th class="p-3">{{ __('Actions') }}</th></tr></thead>
    <tbody>
    @forelse($payments as $payment)
        <tr class="border-t">
            <td class="p-3">{{ $payment->created_at->format('Y-m-d') }}</td>
            <td class="p-3">{{ ucfirst($payment->type) }}</td>
            <td class="p-3 font-semibold">{{ number_format($payment->amount, 2) }}</td>
            <td class="p-3">{{ $payment->note ?? '—' }}</td>
            <td class="p-3">{{ $payment->user->name ?? '—' }}</td>
            <td class="p-3">
                @if(auth()->user()->role === 'admin')
                <form method="POST" action="{{ route('suppliers.payments.destroy', [$supplier, $payment]) }}" class="inline confirm-submit" data-confirm-message="{{ __('Move this entry to Trash? The balance will be adjusted back.') }}">
                    @csrf @method('DELETE')<button class="btn btn-red">{{ __('Delete') }}</button>
                </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="6">{{ __('No supplier payments recorded yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4 mb-6">{{ $payments->links() }}</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<h2 class="font-semibold p-4 border-b">{{ __('Pending Purchase Orders') }}</h2>
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('PO #') }}</th><th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Total') }}</th></tr></thead>
    <tbody>
    @forelse($pendingOrders as $po)
        <tr class="border-t">
            <td class="p-3">{{ $po->id }}</td>
            <td class="p-3">{{ $po->created_at->format('Y-m-d') }}</td>
            <td class="p-3 font-semibold">{{ number_format($po->total, 2) }}</td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="3">{{ __('No pending purchase orders.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
