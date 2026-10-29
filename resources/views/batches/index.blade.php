@extends('layouts.app')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">{{ __('Batches / Expiry') }}</h1>
</div>

@if(!$migrated)
    <div class="bg-yellow-100 text-yellow-800 px-4 py-3 rounded-lg">
        {{ __('Batch tracking tables are not installed yet — please run the v3.0 migrations first.') }}
    </div>
@else
    <div class="bg-amber-50 border border-amber-300 text-amber-800 text-sm px-4 py-2 rounded-lg mb-4">
        {{ __('Note: expired batches are shown in red below, but sales are not blocked on expired stock yet — an expired batch is still consumed earliest-expiry-first on sale. Check this page before selling expired items.') }}
    </div>
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <a href="{{ route('batches.index', ['filter' => 'all']) }}" class="px-3 py-1.5 rounded-full text-sm {{ $filter === 'all' ? 'bg-slate-900 text-white' : 'bg-white text-gray-700 border' }}">{{ __('All') }}</a>
        <a href="{{ route('batches.index', ['filter' => 'expiring']) }}" class="px-3 py-1.5 rounded-full text-sm {{ $filter === 'expiring' ? 'bg-orange-500 text-white' : 'bg-white text-gray-700 border' }}">{{ __('Expiring soon') }}</a>
        <a href="{{ route('batches.index', ['filter' => 'expired']) }}" class="px-3 py-1.5 rounded-full text-sm {{ $filter === 'expired' ? 'bg-red-600 text-white' : 'bg-white text-gray-700 border' }}">{{ __('Expired') }}</a>
        <form method="GET" action="{{ route('batches.index') }}" class="flex gap-2 ml-auto">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('Search by product or batch no') }}" class="border rounded-lg px-3 py-1.5 text-sm w-64">
            <button type="submit" class="bg-slate-900 text-white px-4 py-1.5 rounded-lg text-sm">{{ __('Search') }}</button>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="py-2 px-4">{{ __('Product') }}</th>
                    <th class="px-4">{{ __('Batch No') }}</th>
                    <th class="px-4">{{ __('Expiry Date') }}</th>
                    <th class="px-4">{{ __('Quantity') }}</th>
                    <th class="px-4">{{ __('Remaining') }}</th>
                    <th class="px-4">{{ __('Days left') }}</th>
                    <th class="px-4">{{ __('Purchase Order') }}</th>
                    <th class="px-4">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($batches as $batch)
                    @php($days = $batch->expiry_date ? today()->diffInDays($batch->expiry_date, false) : null)
                    <tr class="border-t">
                        <td class="py-2 px-4 font-medium">
                            @if($batch->product)
                                <a href="{{ route('products.edit', $batch->product) }}" class="underline">{{ $batch->product->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4">{{ $batch->batch_no }}</td>
                        <td class="px-4">{{ $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : '—' }}</td>
                        <td class="px-4">{{ $batch->quantity }} {{ $batch->product->unit ?? '' }}</td>
                        <td class="px-4 font-semibold">{{ $batch->remaining_quantity }} {{ $batch->product->unit ?? '' }}</td>
                        <td class="px-4">
                            @if($days === null)
                                —
                            @elseif($days < 0)
                                <span class="text-red-600 font-semibold">{{ __('Expired') }} {{ abs($days) }} {{ __('days ago') }}</span>
                            @elseif($days <= 30)
                                <span class="text-orange-600 font-semibold">{{ $days }} {{ __('days') }}</span>
                            @else
                                {{ $days }} {{ __('days') }}
                            @endif
                        </td>
                        <td class="px-4">{{ $batch->purchase_order_id ? __('PO #') . $batch->purchase_order_id : '—' }}</td>
                        <td class="px-4"><a href="{{ route('batches.edit', $batch) }}" class="underline">{{ __('Edit') }}</a></td>
                    </tr>
                @empty
                    <tr class="border-t">
                        <td colspan="8" class="py-4 px-4 text-gray-400">{{ __('No batches found') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $batches->links() }}</div>
    <p class="text-xs text-gray-400 mt-2">{{ __('Batches are created when a purchase order is received, consumed earliest-expiry-first on sale, and topped up by customer returns. Product stock itself stays the authority.') }}</p>
@endif
@endsection
