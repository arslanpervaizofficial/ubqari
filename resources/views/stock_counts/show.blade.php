@extends('layouts.app')
@section('title', 'Variance Report — Stock Count #' . $stockCount->id)
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Variance Report — #') }}{{ $stockCount->id }}</h1>
    <a href="{{ route('stock-counts.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back to Stock Counts') }}</a>
</div>

<div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Count Date') }}</div>
        <div class="text-xl font-bold">{{ $stockCount->count_date->format('Y-m-d') }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Counted by') }} {{ $stockCount->user->name ?? '—' }}{{ $stockCount->note ? ' — ' . $stockCount->note : '' }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Status') }}</div>
        <div class="text-xl font-bold">
            @if($stockCount->status === 'approved')
                <span class="text-green-700">{{ __('Approved') }}</span>
            @else
                <span class="text-yellow-700">{{ __('Open') }}</span>
            @endif
        </div>
        <div class="text-xs text-gray-400 mt-1">{{ $stockCount->status === 'approved' ? 'Stock has been adjusted and this count is final.' : 'Not yet posted to stock.' }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Products Counted') }}</div>
        <div class="text-xl font-bold">{{ $countedItems->total() }} / {{ $stockCount->items_count }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Only counted products appear below; uncounted products are never adjusted.') }}</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm">
        <div class="text-gray-500 text-sm">{{ __('Total Variance') }}</div>
        <div class="text-xl font-bold {{ $totalVariance < 0 ? 'text-red-600' : ($totalVariance > 0 ? 'text-green-700' : '') }}">{{ number_format($totalVariance, 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ __('Sum of (counted − snapshot) over counted products.') }}</div>
    </div>
</div>

@if($stockCount->isOpen())
<div class="flex flex-wrap gap-2 mb-4 print:hidden">
    <a href="{{ route('stock-counts.edit', $stockCount) }}" class="btn btn-gray">{{ __('Enter / Edit Counts') }}</a>
    @if($canApprove)
    <form method="POST" action="{{ route('stock-counts.approve', $stockCount) }}" class="inline confirm-submit" data-confirm-message="Approve this count? Every counted product's stock will be set to its counted quantity. This cannot be undone.">
        @csrf<button class="btn btn-solid-green">{{ __('Approve & Adjust Stock') }}</button>
    </form>
    @endif
    @if(auth()->user()->role === 'admin')
    <form method="POST" action="{{ route('stock-counts.destroy', $stockCount) }}" class="inline confirm-submit" data-confirm-message="{{ __('Delete this open count? Its items will be removed and no stock will be changed.') }}">
        @csrf @method('DELETE')<button class="btn btn-red">{{ __('Delete Count') }}</button>
    </form>
    @endif
</div>
@endif

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">{{ __('Product') }}</th><th class="p-3">SKU</th><th class="p-3">{{ __('Unit') }}</th>
        <th class="p-3">{{ __('Snapshot Stock') }}</th><th class="p-3">{{ __('Counted Stock') }}</th><th class="p-3">{{ __('Variance') }}</th>
    </tr></thead>
    <tbody>
    @forelse($countedItems as $item)
        <tr class="border-t">
            <td class="p-3">{{ $item->product->name ?? '—' }}</td>
            <td class="p-3 text-gray-500">{{ $item->product->sku ?? '—' }}</td>
            <td class="p-3 text-gray-500">{{ $item->product->unit ?? '—' }}</td>
            <td class="p-3">{{ number_format($item->snapshot_stock, 2) }}</td>
            <td class="p-3 font-semibold">{{ number_format($item->counted_stock, 2) }}</td>
            <td class="p-3 {{ $item->variance < 0 ? 'text-red-600 font-semibold' : ($item->variance > 0 ? 'text-green-700 font-semibold' : '') }}">{{ number_format($item->variance, 2) }}</td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="6">{{ __('Nothing counted yet. Open "Enter / Edit Counts" to record counted quantities.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $countedItems->links() }}</div>
@endsection
