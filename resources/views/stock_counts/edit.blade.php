@extends('layouts.app')
@section('title', 'Enter Counts — Stock Count #' . $stockCount->id)
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Enter Counts — #') }}{{ $stockCount->id }} ({{ $stockCount->count_date->format('Y-m-d') }})</h1>
    <a href="{{ route('stock-counts.show', $stockCount) }}" class="btn btn-gray">{{ __('Variance Report') }}</a>
</div>

<p class="text-sm text-gray-500 mb-4">{{ __('Snapshot figures are frozen from count creation and cannot be changed here. Enter what is actually on the shelf; leave a box blank and that product stays uncounted (it will not be adjusted on approval). Saving one page never touches the other pages.') }}</p>

<form method="POST" action="{{ route('stock-counts.update', $stockCount) }}" novalidate>
    @csrf @method('PATCH')
    <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr>
            <th class="p-3">{{ __('Product') }}</th><th class="p-3">SKU</th><th class="p-3">{{ __('Unit') }}</th>
            <th class="p-3">{{ __('Snapshot Stock') }}</th><th class="p-3">{{ __('Counted Qty') }}</th><th class="p-3">{{ __('Variance') }}</th>
        </tr></thead>
        <tbody>
        @forelse($items as $item)
            <tr class="border-t">
                <td class="p-3">{{ $item->product->name ?? '—' }}</td>
                <td class="p-3 text-gray-500">{{ $item->product->sku ?? '—' }}</td>
                <td class="p-3 text-gray-500">{{ $item->product->unit ?? '—' }}</td>
                <td class="p-3">{{ number_format($item->snapshot_stock, 2) }}</td>
                <td class="p-3">
                    <input type="number" step="0.01" min="0" name="counted[{{ $item->id }}]" value="{{ old('counted.'.$item->id, $item->counted_stock) }}" placeholder="{{ __('Blank = not counted') }}" class="border rounded px-3 py-2 w-40">
                    @error('counted.'.$item->id) <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </td>
                <td class="p-3 {{ $item->variance === null ? 'text-gray-400' : ($item->variance < 0 ? 'text-red-600 font-semibold' : ($item->variance > 0 ? 'text-green-700 font-semibold' : '')) }}">
                    {{ $item->variance === null ? '—' : number_format($item->variance, 2) }}
                </td>
            </tr>
        @empty
            <tr><td class="p-3 text-gray-400" colspan="6">{{ __('No products in this count.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button class="btn btn-solid-green">{{ __('Save This Page') }}</button>
        <a href="{{ route('stock-counts.index') }}" class="btn btn-gray">{{ __('Back to Stock Counts') }}</a>
    </div>
</form>
<div class="mt-4">{{ $items->links() }}</div>
@endsection
