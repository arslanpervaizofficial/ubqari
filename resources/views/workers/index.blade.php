@extends('layouts.app')
@section('title', __('Workers'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Workers') }}</h1>
    @if(auth()->user()->role === 'admin')
    <a href="{{ route('workers.create') }}" class="btn btn-dark">+ {{ __('Add Worker') }}</a>
    @endif
</div>
<p class="text-sm text-gray-500 mb-4">{{ __('Workers have no login and no role. They exist only to name who is responsible for a bill: type a worker code at billing time and the bill shows "Billed by" with that name. Only an admin can add or change workers.') }}</p>
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">{{ __('Worker') }}</th><th class="p-3">{{ __('Contact') }}</th><th class="p-3">{{ __('CNIC') }}</th><th class="p-3">{{ __('Orders') }}</th><th class="p-3">{{ __('Status') }}</th><th class="p-3">{{ __('Actions') }}</th>
    </tr></thead>
    <tbody>
    @forelse($workers as $w)
        <tr class="border-t hover:bg-gray-50 {{ !$w->is_active ? 'opacity-60' : '' }}">
            <td class="p-3">
                <div class="flex items-center gap-3">
                    <img src="{{ $w->image_url }}" class="w-9 h-9 rounded-full object-cover">
                    <div>
                        <a href="{{ route('workers.show', $w) }}" class="font-medium hover:underline">{{ $w->name }}</a>
                        <div class="text-xs text-gray-500">{{ $w->code }}</div>
                    </div>
                </div>
            </td>
            <td class="p-3">
                <div>{{ $w->phone ?? '—' }}</div>
                @if($w->email)<div class="text-xs text-gray-500">{{ $w->email }}</div>@endif
            </td>
            <td class="p-3 text-gray-600">{{ $w->cnic ?? '—' }}</td>
            <td class="p-3">{{ $orderCounts[$w->code] ?? 0 }}</td>
            <td class="p-3">
                <span class="px-2 py-1 rounded text-xs {{ $w->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $w->is_active ? __('Active') : __('Disabled') }}</span>
            </td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <a href="{{ route('workers.show', $w) }}" class="btn btn-gray">{{ __('View') }}</a>
                @if(auth()->user()->role === 'admin')
                <a href="{{ route('workers.edit', $w) }}" class="btn btn-blue">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('workers.toggle-active', $w) }}" class="inline">
                    @csrf
                    <button class="btn {{ $w->is_active ? 'btn-yellow' : 'btn-solid-green' }}">{{ $w->is_active ? __('Disable') : __('Enable') }}</button>
                </form>
                <form method="POST" action="{{ route('workers.destroy', $w) }}" class="inline confirm-submit" data-confirm-message="{{ __('Delete this worker? Bills already made keep the name and code as text.') }}">
                    @csrf @method('DELETE')<button class="btn btn-red">{{ __('Delete') }}</button>
                </form>
                @else
                <span class="text-gray-400 text-xs">{{ __('Admin only') }}</span>
                @endif
            </td>
        </tr>
    @empty
        <tr class="border-t"><td class="p-3 text-gray-500" colspan="6">{{ __('No workers yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $workers->links() }}</div>
@endsection
