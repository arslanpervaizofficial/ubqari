@extends('layouts.app')
@section('title', __('Customer Aging'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Customer Aging') }}</h1>
    <div class="flex gap-2">
        <a href="{{ route('reports.customers') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Customer Report') }}</a>
        <a href="{{ route('reports.index') }}" class="btn btn-gray">{{ __('Back to Reports') }}</a>
    </div>
</div>

<p class="text-sm text-gray-600 mb-4">
    {{ __('Open dues as of') }} <strong>{{ $asOf }}</strong> {{ __('(Asia/Karachi). Each completed order with a due amount over 0 is aged by its order date (the original completion date when set, otherwise the order date) and placed in one bucket: 0-30, 31-60, 61-90, or 90+ days old.') }}
</p>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">{{ __('Customer') }}</th>
        <th class="p-3">{{ __('Phone') }}</th>
        <th class="p-3 text-right">{{ __('0-30 days') }}</th>
        <th class="p-3 text-right">{{ __('31-60 days') }}</th>
        <th class="p-3 text-right">{{ __('61-90 days') }}</th>
        <th class="p-3 text-right">{{ __('90+ days') }}</th>
        <th class="p-3 text-right">{{ __('Total open dues') }}</th>
        <th class="p-3 text-right">{{ __('Credit balance') }}</th>
        <th class="p-3 text-right">{{ __('Difference') }}</th>
        <th class="p-3">{{ __('Actions') }}</th>
    </tr></thead>
    <tbody>
    @forelse($customers as $c)
        @php($row = $rows[$c->id])
        @php($diff = $differences[$c->id])
        <tr class="border-t {{ !$c->is_active ? 'opacity-60' : '' }}">
            <td class="p-3 font-medium">{{ $c->name }}</td>
            <td class="p-3">{{ $c->phone ?? '-' }}</td>
            <td class="p-3 text-right">{{ number_format($row['buckets']['bucket_0_30'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($row['buckets']['bucket_31_60'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($row['buckets']['bucket_61_90'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($row['buckets']['bucket_90_plus'], 2) }}</td>
            <td class="p-3 text-right {{ $row['total'] > 0 ? 'text-red-600 font-semibold' : '' }}">{{ number_format($row['total'], 2) }}</td>
            <td class="p-3 text-right {{ $c->credit_balance > 0 ? 'text-red-600 font-semibold' : '' }}">{{ number_format($c->credit_balance, 2) }}</td>
            <td class="p-3 text-right">
                {{ number_format($diff, 2) }}
                @if(abs($diff) > 0.005)
                    <span class="block mt-1"><span class="px-2 py-1 rounded text-xs bg-yellow-100 text-yellow-800">{{ __('Differs — see note') }}</span></span>
                @else
                    <span class="block mt-1"><span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700">{{ __('Matches') }}</span></span>
                @endif
            </td>
            <td class="p-3 whitespace-nowrap">
                <a href="{{ route('aging.show', $c) }}" class="btn btn-blue">{{ __('Detail') }}</a>
                <a href="{{ route('customers.ledger', $c) }}" class="btn btn-gray">{{ __('Ledger') }}</a>
            </td>
        </tr>
    @empty
        <tr class="border-t"><td class="p-3 text-gray-500" colspan="10">{{ __('No customers have open dues or a non-zero credit balance.') }}</td></tr>
    @endforelse
    </tbody>
    <tfoot class="bg-gray-50 font-semibold border-t-2">
        <tr>
            <td class="p-3" colspan="2">{{ __('Grand total — all named customers (all pages,') }} {{ $grandOrdersCount }} open due order(s))</td>
            <td class="p-3 text-right">{{ number_format($grandBuckets['bucket_0_30'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($grandBuckets['bucket_31_60'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($grandBuckets['bucket_61_90'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($grandBuckets['bucket_90_plus'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($grandTotal, 2) }}</td>
            <td class="p-3 text-right">{{ number_format($grandBalance, 2) }}</td>
            <td class="p-3 text-right">{{ number_format($grandDifference, 2) }}</td>
            <td class="p-3"></td>
        </tr>
    </tfoot>
</table>
</div>
<div class="mt-4">{{ $customers->links() }}</div>

{{-- Reconciliation note: the flag is informational, never an error to fix here. --}}
<div class="bg-white rounded-xl shadow-sm p-4 mt-6 text-sm text-gray-600">
    <p class="font-semibold text-gray-800 mb-1">{{ __('About the Difference column (reconciliation)') }}</p>
    <p>
        {{ __('Total open dues come only from completed orders with a due amount over 0. The credit balance is the customer\'s stored ledger balance, which also includes manual ledger debits and credits that have no order, and post-return credit adjustments. So the two figures can legitimately differ, and a "Differs" flag is information for review, not an error — nothing is corrected or rewritten from this page. A customer can also appear here with zero open dues but a non-zero credit balance (manual ledger entries only); a customer with no open dues and a zero balance does not appear.') }}
    </p>
</div>

{{-- Walk-in dues: no customer record exists, so one separate bucketed line, --}}
{{-- deliberately NOT included in the grand total above (named customers only). --}}
<h2 class="font-semibold text-gray-600 mt-6 mb-2">{{ __('Walk-in dues (no customer record)') }}</h2>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">{{ __('Walk-in (no account)') }}</th>
        <th class="p-3 text-right">{{ __('0-30 days') }}</th>
        <th class="p-3 text-right">{{ __('31-60 days') }}</th>
        <th class="p-3 text-right">{{ __('61-90 days') }}</th>
        <th class="p-3 text-right">{{ __('90+ days') }}</th>
        <th class="p-3 text-right">{{ __('Total walk-in dues') }}</th>
        <th class="p-3">{{ __('Open due order(s)') }}</th>
    </tr></thead>
    <tbody>
        <tr class="border-t">
            <td class="p-3 font-medium">{{ __('Walk-in dues (no customer record)') }}</td>
            <td class="p-3 text-right">{{ number_format($walkInBuckets['bucket_0_30'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($walkInBuckets['bucket_31_60'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($walkInBuckets['bucket_61_90'], 2) }}</td>
            <td class="p-3 text-right">{{ number_format($walkInBuckets['bucket_90_plus'], 2) }}</td>
            <td class="p-3 text-right {{ $walkInTotal > 0 ? 'text-red-600 font-semibold' : '' }}">{{ number_format($walkInTotal, 2) }}</td>
            <td class="p-3">{{ $walkInOrdersCount }}</td>
        </tr>
    </tbody>
</table>
</div>

<p class="text-xs text-gray-500 mt-4">
    {{ __('Bucket note: the buckets are disjoint so they always sum to the row total — "90+ days" means strictly over 90 days, so an order exactly 90 days old falls in 61-90. An order dated in the future is counted as 0 days old (0-30 bucket). The grand total covers named customers only; walk-in dues are shown separately above and are not added into it.') }}
</p>
@endsection
