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
        <th class="p-3">{{ __('Name') }}</th><th class="p-3">{{ __('Worker Code') }}</th><th class="p-3">{{ __('Orders') }}</th><th class="p-3">{{ __('Status') }}</th><th class="p-3">{{ __('Actions') }}</th>
    </tr></thead>
    <tbody>
    @forelse($workers as $w)
        <tr class="border-t {{ !$w->is_active ? 'opacity-60' : '' }}">
            <td class="p-3 font-medium">{{ $w->name }}</td>
            <td class="p-3">{{ $w->code }}</td>
            <td class="p-3">{{ $orderCounts[$w->code] ?? 0 }}</td>
            <td class="p-3">
                <span class="px-2 py-1 rounded text-xs {{ $w->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $w->is_active ? __('Active') : __('Disabled') }}</span>
            </td>
            <td class="p-3 space-x-1 whitespace-nowrap">
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
        <tr class="border-t"><td class="p-3 text-gray-500" colspan="5">{{ __('No workers yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $workers->links() }}</div>
@endsection
