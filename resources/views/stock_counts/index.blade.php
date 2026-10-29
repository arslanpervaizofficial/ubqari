@extends('layouts.app')
@section('title', __('Stock Counts'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Stock Counts') }}</h1>
    <a href="{{ route('stock-counts.create') }}" class="btn btn-solid-green">+ {{ __('New Count') }}</a>
</div>

<p class="text-sm text-gray-500 mb-4">{{ __('A physical count snapshots every product\'s system stock, lets you enter what is actually on the shelf, and — once approved — adjusts stock to the counted figures. Uncounted products are never touched, and an approved count can never be changed.') }}</p>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">#</th><th class="p-3">{{ __('Count Date') }}</th><th class="p-3">{{ __('Status') }}</th>
        <th class="p-3">{{ __('Counted By') }}</th><th class="p-3">{{ __('Progress') }}</th><th class="p-3">{{ __('Note') }}</th><th class="p-3">{{ __('Actions') }}</th>
    </tr></thead>
    <tbody>
    @forelse($counts as $count)
        <tr class="border-t">
            <td class="p-3 font-semibold">#{{ $count->id }}</td>
            <td class="p-3">{{ $count->count_date->format('Y-m-d') }}</td>
            <td class="p-3">
                @if($count->status === 'approved')
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">{{ __('Approved') }}</span>
                @else
                    <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">{{ __('Open') }}</span>
                @endif
            </td>
            <td class="p-3">{{ $count->user->name ?? '—' }}</td>
            <td class="p-3">{{ $count->counted_items_count }} / {{ $count->items_count }} {{ __('counted') }}</td>
            <td class="p-3 text-gray-500">{{ $count->note ?? '—' }}</td>
            <td class="p-3">
                <a href="{{ route('stock-counts.show', $count) }}" class="btn btn-blue">{{ __('View') }}</a>
                @if($count->status === 'open')
                    <a href="{{ route('stock-counts.edit', $count) }}" class="btn btn-gray">{{ __('Enter Counts') }}</a>
                    @if(auth()->user()->role === 'admin')
                    <form method="POST" action="{{ route('stock-counts.destroy', $count) }}" class="inline confirm-submit" data-confirm-message="{{ __('Delete this open count? Its items will be removed and no stock will be changed.') }}">
                        @csrf @method('DELETE')<button class="btn btn-red">{{ __('Delete') }}</button>
                    </form>
                    @endif
                @endif
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="7">{{ __('No stock counts yet. Start one to snapshot current stock.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $counts->links() }}</div>
@endsection
