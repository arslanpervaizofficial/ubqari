@extends('layouts.app')
@section('title', __('New Stock Count'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('New Stock Count') }}</h1>
    <a href="{{ route('stock-counts.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back to Stock Counts') }}</a>
</div>

<form method="POST" action="{{ route('stock-counts.store') }}" novalidate>
    @csrf
    <div class="bg-white rounded-xl shadow-sm p-5 mb-6">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Count Date') }}</label>
                <input type="date" name="count_date" value="{{ old('count_date', now()->toDateString()) }}" required class="border rounded px-3 py-2">
                @error('count_date') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm text-gray-600 mb-1">{{ __('Note (optional)') }}</label>
                <input name="note" value="{{ old('note') }}" placeholder="{{ __('e.g. Monthly full-store count') }}" class="border rounded px-3 py-2 w-full">
                @error('note') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-solid-green">{{ __('Create Count & Snapshot All Products') }}</button>
        </div>
        <p class="text-xs text-gray-400 mt-3">{{ __('Creating the count freezes every product\'s current system stock as its snapshot. Leave a counted box blank and that product stays uncounted — it will not be adjusted on approval. Counted figures typed on this page are saved with the count; use "Enter Counts" afterwards for the remaining pages.') }}</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr>
            <th class="p-3">{{ __('Product') }}</th><th class="p-3">SKU</th><th class="p-3">{{ __('Unit') }}</th>
            <th class="p-3">{{ __('System Stock (snapshot)') }}</th><th class="p-3">{{ __('Counted Qty') }}</th>
        </tr></thead>
        <tbody>
        @forelse($products as $product)
            <tr class="border-t">
                <td class="p-3">{{ $product->name }}</td>
                <td class="p-3 text-gray-500">{{ $product->sku }}</td>
                <td class="p-3 text-gray-500">{{ $product->unit }}</td>
                <td class="p-3">{{ number_format($product->stock, 2) }}</td>
                <td class="p-3">
                    <input type="number" step="0.01" min="0" name="counted[{{ $product->id }}]" value="{{ old('counted.'.$product->id) }}" placeholder="{{ __('Blank = not counted') }}" class="border rounded px-3 py-2 w-40">
                    @error('counted.'.$product->id) <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </td>
            </tr>
        @empty
            <tr><td class="p-3 text-gray-400" colspan="5">{{ __('No products yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</form>
<div class="mt-4">{{ $products->links() }}</div>
@endsection
