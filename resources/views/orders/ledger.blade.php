@extends('layouts.app')
@section('title', $order->order_number . ' — Ledger')
@section('content')
<div class="flex justify-between items-center mb-1">
    <h1 class="text-2xl font-bold">{{ $order->order_number }} {{ __('— Ledger') }}</h1>
    <a href="{{ route('reports.walk-in-detail') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back') }}</a>
</div>
<p class="text-gray-600 mb-4">
    {{ $order->customer->name ?? __('Walk-in customer') }} {{ __('— Total') }}: {{ number_format($order->total, 2) }}{{ __(', Paid') }}: {{ number_format($order->paid_amount, 2) }}{{ __(', Due') }}: <span class="font-bold {{ $order->due_amount > 0 ? 'text-red-600' : '' }}">{{ number_format($order->due_amount, 2) }}</span>
</p>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h2 class="font-semibold mb-3">{{ __('Record Credit/Debit') }}</h2>
    <form method="POST" action="{{ route('orders.record-payment', $order) }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="flex gap-2">
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-green-500 has-[:checked]:bg-green-50 text-sm">
                <input type="radio" name="type" value="credit" checked> {{ __('Credit (payment received)') }}
            </label>
            <label class="border rounded-lg px-3 py-2 flex items-center gap-2 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50 text-sm">
                <input type="radio" name="type" value="debit"> {{ __('Debit (extra charge)') }}
            </label>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Amount') }}</label>
            <input type="number" step="0.01" min="0.01" name="amount" required data-label="Amount" class="border rounded px-3 py-2 w-40">
            @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Note (optional)') }}</label>
            <input name="note" placeholder="{{ __('e.g. Paid remaining balance in cash') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <button class="btn btn-solid-green">{{ __('Save') }}</button>
    </form>
</div>

@if($order->due_amount > 0)
<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h2 class="font-semibold mb-3">{{ __('Pay online (JazzCash/Easypaisa)') }}</h2>
    <form method="POST" action="{{ route('orders.initiate-online-payment', $order) }}" novalidate class="flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Gateway') }}</label>
            <select name="gateway" required data-label="Gateway" class="border rounded px-3 py-2">
                <option value="jazzcash">{{ __('JazzCash') }}</option>
                <option value="easypaisa">{{ __('Easypaisa') }}</option>
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">{{ __('Amount') }}</label>
            <input type="number" step="0.01" min="0.01" max="{{ $order->due_amount }}" name="amount" value="{{ $order->due_amount }}" required data-label="Amount" class="border rounded px-3 py-2 w-40">
            @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <button class="btn btn-solid-green">{{ __('Pay online (JazzCash/Easypaisa)') }}</button>
    </form>
    <p class="text-xs text-gray-400 mt-2">{{ __('You will be sent to the gateway\'s own page to pay. The payment is only added to this ledger after the gateway confirms it.') }}</p>
</div>
@endif

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Type') }}</th><th class="p-3">{{ __('Amount') }}</th><th class="p-3">{{ __('Note') }}</th><th class="p-3">{{ __('Recorded By') }}</th></tr></thead>
    <tbody>
    @forelse($payments as $pmt)
        <tr class="border-t">
            <td class="p-3">{{ $pmt->created_at->format('Y-m-d H:i') }}</td>
            <td class="p-3">
                @if($pmt->type === 'credit')
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">{{ __('Credit') }}</span>
                @else
                    <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">{{ __('Debit') }}</span>
                @endif
            </td>
            <td class="p-3 font-semibold {{ $pmt->type === 'credit' ? 'text-green-700' : 'text-red-700' }}">
                {{ $pmt->type === 'credit' ? '-' : '+' }}{{ number_format($pmt->amount, 2) }}
            </td>
            <td class="p-3">{{ $pmt->note }}</td>
            <td class="p-3">{{ $pmt->user->name ?? '—' }}</td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="5">{{ __('No credit/debit entries yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
