@extends('layouts.app')
@section('title', __('Online Payments'))
@section('content')
<h1 class="text-2xl font-bold mb-4">{{ __('Online Payments') }}</h1>

<p class="text-xs text-gray-400 mb-4">{{ __('Every JazzCash and Easypaisa payment started from an order ledger, newest first. A pending row means the gateway has not confirmed it yet — it is not an error on its own. There is no cancel button here on purpose: marking a pending payment failed by hand would also refuse a late genuine gateway callback for it, so for a stale pending payment, start a fresh payment from the order ledger instead.') }}</p>

<form method="GET" class="flex flex-wrap gap-2 mb-4">
    <select name="gateway" class="border rounded px-3 py-2">
        <option value="">{{ __('All gateways') }}</option>
        <option value="jazzcash" {{ $gateway === 'jazzcash' ? 'selected' : '' }}>JazzCash</option>
        <option value="easypaisa" {{ $gateway === 'easypaisa' ? 'selected' : '' }}>Easypaisa</option>
    </select>
    <select name="status" class="border rounded px-3 py-2">
        <option value="">{{ __('All statuses') }}</option>
        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
        <option value="paid" {{ $status === 'paid' ? 'selected' : '' }}>{{ __('Paid') }}</option>
        <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
    </select>
    <button class="btn btn-gray">{{ __('Filter') }}</button>
    <a href="{{ route('online-payments.index') }}" class="btn btn-gray">{{ __('Clear') }}</a>
</form>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead>
        <tr class="text-left text-gray-500 border-b">
            <th class="px-4 py-3">{{ __('Date') }}</th>
            <th class="px-4 py-3">{{ __('Gateway') }}</th>
            <th class="px-4 py-3">{{ __('Order') }}</th>
            <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
            <th class="px-4 py-3">{{ __('Status') }}</th>
            <th class="px-4 py-3">{{ __('Gateway Transaction ID') }}</th>
            <th class="px-4 py-3">{{ __('Started by') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse($payments as $payment)
        <tr class="border-t">
            <td class="px-4 py-3">{{ $payment->created_at ? $payment->created_at->format('Y-m-d H:i') : '—' }}</td>
            <td class="px-4 py-3">{{ ucfirst($payment->gateway) }}</td>
            <td class="px-4 py-3">
                @if($payment->order)
                    <a href="{{ route('orders.ledger', $payment->order) }}" class="underline">{{ $payment->order->order_number ?: ('#' . $payment->order->id) }}</a>
                @else
                    #{{ $payment->order_id }}
                @endif
            </td>
            <td class="px-4 py-3 text-right">{{ number_format($payment->amount, 2) }}</td>
            <td class="px-4 py-3">
                <span class="{{ $payment->status === 'paid' ? 'text-green-700 font-semibold' : ($payment->status === 'failed' ? 'text-red-600 font-semibold' : 'text-yellow-700') }}">{{ ucfirst($payment->status) }}</span>
            </td>
            <td class="px-4 py-3">{{ $payment->gateway_txn_id ?: '—' }}</td>
            <td class="px-4 py-3">{{ $payment->user->name ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">{{ __('No online payments found.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>

<div class="mt-4">{{ $payments->links() }}</div>
@endsection
