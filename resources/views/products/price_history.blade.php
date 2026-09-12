@extends('layouts.app')
@section('title', 'Price History')
@section('content')
<h1 class="text-2xl font-bold mb-4">Price History — {{ $product->name }}</h1>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr><th class="p-3">Date</th><th class="p-3">Old Price</th><th class="p-3">New Price</th><th class="p-3">Changed By</th></tr></thead>
    <tbody>
    @foreach($history as $h)
        <tr class="border-t">
            <td class="p-3">{{ $h->created_at->format('Y-m-d H:i') }}</td>
            <td class="p-3">{{ number_format($h->old_price, 2) }}</td>
            <td class="p-3">{{ number_format($h->new_price, 2) }}</td>
            <td class="p-3">{{ $h->changedBy->name ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endsection
