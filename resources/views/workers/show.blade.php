@extends('layouts.app')
@section('title', __('Worker Details'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Worker Details') }}</h1>
    <div class="flex gap-2">
        <a href="{{ route('workers.index') }}" class="btn btn-gray">{{ __('Back') }}</a>
        @if(auth()->user()->role === 'admin')
        <a href="{{ route('workers.edit', $worker) }}" class="btn btn-blue">{{ __('Edit') }}</a>
        @endif
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm p-6 w-full mb-4">
    <div class="flex items-center gap-4 mb-5">
        <img src="{{ $worker->image_url }}" class="w-20 h-20 rounded-full object-cover border">
        <div>
            <div class="text-xl font-bold">{{ $worker->name }}</div>
            <div class="text-sm text-gray-500">{{ $worker->code }}</div>
            <span class="inline-block mt-1 px-2 py-1 rounded text-xs {{ $worker->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $worker->is_active ? __('Active') : __('Disabled') }}</span>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
            <div class="text-sm text-gray-500">{{ __('Phone Number') }}</div>
            <div class="font-medium">{{ $worker->phone ?? '—' }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500">{{ __('CNIC') }}</div>
            <div class="font-medium">{{ $worker->cnic ?? '—' }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500">{{ __('Email') }}</div>
            <div class="font-medium">{{ $worker->email ?? '—' }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500">{{ __('Orders Made') }}</div>
            <div class="font-medium">{{ $ordersCount }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500">{{ __('Total Billed') }}</div>
            <div class="font-medium">{{ number_format($totalBilled, 2) }}</div>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <h2 class="font-semibold p-4 pb-0">{{ __('Recent Orders') }}</h2>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr>
            <th class="p-3">{{ __('Order #') }}</th><th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('Customer') }}</th><th class="p-3">{{ __('Total') }}</th>
        </tr></thead>
        <tbody>
        @forelse($orders as $o)
            <tr class="border-t hover:bg-gray-50">
                <td class="p-3"><a href="{{ route('pos.invoice', $o) }}" class="font-medium hover:underline">{{ $o->order_number }}</a></td>
                <td class="p-3 text-gray-600">{{ $o->created_at?->format('d M Y') ?? '—' }}</td>
                <td class="p-3">{{ $o->customer->name ?? 'Walk-in' }}</td>
                <td class="p-3">{{ number_format($o->total, 2) }}</td>
            </tr>
        @empty
            <tr class="border-t"><td class="p-3 text-gray-500" colspan="4">{{ __('No orders yet for this worker.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
