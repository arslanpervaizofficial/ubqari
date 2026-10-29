@extends('layouts.app')
@section('title', 'Customer Aging — ' . $customer->name)
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Customer Aging —') }} {{ $customer->name }}</h1>
    <div class="flex gap-2">
        <a href="{{ route('customers.ledger', $customer) }}" class="btn btn-gray">{{ __('Ledger') }}</a>
        <a href="{{ route('aging.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back to Aging') }}</a>
    </div>
</div>

<p class="text-sm text-gray-600 mb-4">
    {{ __('Phone') }}: {{ $customer->phone ?? '-' }} &nbsp;|&nbsp; Open dues as of <strong>{{ $asOf }}</strong> {{ __('(Asia/Karachi). Only completed orders with a due amount over 0 are listed here.') }}
</p>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto mb-6">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3 text-right">{{ __('0-30 days') }}</th>
        <th class="p-3 text-right">{{ __('31-60 days') }}</th>
        <th class="p-3 text-right">{{ __('61-90 days') }}</th>
        <th class="p-3 text-right">{{ __('90+ days') }}</th>
        <th class="p-3 text-right">{{ __('Total open dues') }}</th>
        <th class="p-3 text-right">{{ __('Credit balance') }}</th>
        <th class="p-3 text-right">{{ __('Difference') }}</th>
    </tr></thead>
    <tbody>
        <tr class="border-t font-semibold">
            <td class="p-3 text-right">{{ number_format($buckets['bucket_0_30'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($buckets['bucket_31_60'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($buckets['bucket_61_90'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($buckets['bucket_90_plus'], 2) }}</td>
            <td class="p-3 text-right {{ $total > 0 ? 'text-red-600' : '' }}">{{ number_format($total, 2) }}</td>
            <td class="p-3 text-right {{ $customer->credit_balance > 0 ? 'text-red-600' : '' }}">{{ number_format($customer->credit_balance, 2) }}</td>
            <td class="p-3 text-right">
                {{ number_format($difference, 2) }}
                @if(abs($difference) > 0.005)
                    <span class="block mt-1"><span class="px-2 py-1 rounded text-xs bg-yellow-100 text-yellow-800">{{ __('Differs — see note on the Aging list') }}</span></span>
                @else
                    <span class="block mt-1"><span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700">{{ __('Matches') }}</span></span>
                @endif
            </td>
        </tr>
    </tbody>
</table>
</div>

<h2 class="font-semibold text-gray-600 mb-2">{{ __('Open due orders') }}</h2>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">{{ __('Order #') }}</th>
        <th class="p-3">{{ __('Order date') }}</th>
        <th class="p-3 text-right">{{ __('Age (days)') }}</th>
        <th class="p-3">{{ __('Bucket') }}</th>
        <th class="p-3 text-right">{{ __('Due amount') }}</th>
    </tr></thead>
    <tbody>
    @forelse($orders as $order)
        @php($a = $aging[$order->id])
        <tr class="border-t">
            <td class="p-3 font-medium">{{ $order->order_number }}</td>
            <td class="p-3">{{ $a['date'] }}</td>
            <td class="p-3 text-right">{{ $a['age'] }}</td>
            <td class="p-3">{{ $a['bucket_label'] }}</td>
            <td class="p-3 text-right text-red-600 font-semibold">{{ number_format($order->due_amount, 2) }}</td>
        </tr>
    @empty
        <tr class="border-t"><td class="p-3 text-gray-500" colspan="5">{{ __('This customer has no open due orders.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $orders->links() }}</div>

<p class="text-xs text-gray-500 mt-4">
    {{ __('Bucket note: "90+ days" means strictly over 90 days, so an order exactly 90 days old falls in 61-90. The difference is informational only: the credit balance also includes manual ledger debits/credits with no order and post-return credit adjustments, so it can legitimately differ from order dues.') }}
</p>
@endsection
