@extends('layouts.app')
@section('title', 'Suppliers')
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">Suppliers</h1>
    <a href="{{ route('suppliers.create') }}" class="btn btn-blue !bg-gray-900 !text-white">+ Add Supplier</a>
</div>
<div data-ajax-list="suppliers">
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="suppliers" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="suppliers"
            data-action-url="{{ route('suppliers.destroy-selected') }}"
            data-confirm-message="Move the selected suppliers to Trash?">
        Delete Selected
    </button>
    <button type="button" class="btn btn-red" data-bulk-all-submit
            data-action-url="{{ route('suppliers.destroy-all') }}"
            data-confirm-message="Move ALL suppliers on this list to Trash?">
        Delete All
    </button>
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        @if(auth()->user()->role === 'admin')<th class="p-3"><input type="checkbox" data-bulk-select-all="suppliers"></th>@endif
        <th class="p-3"></th><th class="p-3">Name</th><th class="p-3">Phone</th><th class="p-3">Email</th><th class="p-3">Status</th><th class="p-3">Actions</th>
    </tr></thead>
    <tbody>
    @foreach($suppliers as $s)
        <tr class="border-t {{ !$s->is_active ? 'opacity-60' : '' }}">
            @if(auth()->user()->role === 'admin')<td class="p-3"><input type="checkbox" data-bulk-item="suppliers" value="{{ $s->id }}"></td>@endif
            <td class="p-3"><img src="{{ $s->image_url }}" class="w-9 h-9 rounded-full object-cover"></td>
            <td class="p-3 font-medium">{{ $s->name }}</td>
            <td class="p-3">{{ $s->phone }}</td>
            <td class="p-3">{{ $s->email }}</td>
            <td class="p-3">
                <span class="px-2 py-1 rounded text-xs {{ $s->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $s->is_active ? 'Active' : 'Disabled' }}</span>
            </td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                <a href="{{ route('suppliers.edit', $s) }}" class="btn btn-blue">Edit</a>
                <form method="POST" action="{{ route('suppliers.toggle-active', $s) }}" class="inline">
                    @csrf
                    <button class="btn {{ $s->is_active ? 'btn-yellow' : 'btn-solid-green' }}">{{ $s->is_active ? 'Disable' : 'Enable' }}</button>
                </form>
                @if(auth()->user()->role === 'admin')
                <form method="POST" action="{{ route('suppliers.destroy', $s) }}" class="inline confirm-submit" data-confirm-message="Move this supplier to Trash? You can restore them anytime from Trash.">
                    @csrf @method('DELETE')<button class="btn btn-red">Delete</button>
                </form>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
<div class="mt-4">{{ $suppliers->links() }}</div>
</div>
@endsection
