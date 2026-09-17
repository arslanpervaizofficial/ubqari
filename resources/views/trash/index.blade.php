@extends('layouts.app')
@section('title', 'Trash')
@section('content')
<h1 class="text-2xl font-bold mb-1">Trash</h1>
<p class="text-sm text-gray-500 mb-4">Anything deleted from Customers, Suppliers, Orders, Purchase Orders, Stock Returns{{ auth()->user()->role === 'admin' ? ', or Users' : '' }} ends up here first. Restore it, or permanently delete it — permanent deletion can't be undone.</p>

<div class="flex gap-2 mb-4 flex-wrap">
    @foreach($types as $slug => $t)
        <button type="button" class="btn trash-tab-btn {{ $loop->first ? 'btn-dark' : 'btn-gray' }}" data-trash-tab="{{ $slug }}">
            {{ $t['label'] }} ({{ $trashed[$slug]->count() }})
        </button>
    @endforeach
</div>

@foreach($types as $slug => $t)
<div class="trash-tab-panel {{ $loop->first ? '' : 'hidden' }}" data-trash-panel="{{ $slug }}">
    @if($trashed[$slug]->isEmpty())
        <div class="bg-white rounded-xl shadow-sm p-6 text-gray-400 text-sm">Nothing in Trash here.</div>
    @else
        <div class="flex items-center gap-2 mb-3 flex-wrap">
            <span data-bulk-count="trash-{{ $slug }}" class="text-sm text-gray-500"></span>
            <button type="button" class="btn btn-solid-green" data-bulk-submit="trash-{{ $slug }}"
                    data-action-url="{{ route('trash.restore', $slug) }}"
                    data-confirm-message="Restore the selected items?">
                Restore Selected
            </button>
            <button type="button" class="btn btn-solid-green" data-bulk-all-submit
                    data-action-url="{{ route('trash.restore-all', $slug) }}"
                    data-confirm-message="Restore ALL items in {{ $t['label'] }} Trash?">
                Restore All
            </button>
            @if(auth()->user()->role === 'admin')
            <button type="button" class="btn btn-red" data-bulk-submit="trash-{{ $slug }}" data-http-method="DELETE"
                    data-action-url="{{ route('trash.force-delete', $slug) }}"
                    data-confirm-message="Permanently delete the selected items? This cannot be undone.">
                Permanently Delete Selected
            </button>
            <button type="button" class="btn btn-red" data-bulk-all-submit data-http-method="DELETE"
                    data-action-url="{{ route('trash.force-delete-all', $slug) }}"
                    data-confirm-message="Permanently delete ALL items in {{ $t['label'] }} Trash? This cannot be undone.">
                Permanently Delete All
            </button>
            @endif
        </div>
        <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left"><tr>
                <th class="p-3"><input type="checkbox" data-bulk-select-all="trash-{{ $slug }}"></th>
                <th class="p-3">Item</th>
                <th class="p-3">Deleted</th>
                <th class="p-3">Actions</th>
            </tr></thead>
            <tbody>
            @foreach($trashed[$slug] as $item)
                <tr class="border-t">
                    <td class="p-3"><input type="checkbox" data-bulk-item="trash-{{ $slug }}" value="{{ $item->id }}"></td>
                    <td class="p-3">
                        @if($slug === 'customers' || $slug === 'suppliers')
                            <span class="font-medium">{{ $item->name }}</span> <span class="text-gray-400">{{ $item->phone }}</span>
                        @elseif($slug === 'orders')
                            <span class="font-medium">{{ $item->order_number }}</span>
                            <span class="text-gray-400">{{ $item->customer->name ?? 'Walk-in' }} — {{ number_format($item->total, 2) }}</span>
                        @elseif($slug === 'purchase-orders')
                            <span class="font-medium">PO #{{ $item->id }}</span>
                            <span class="text-gray-400">{{ $item->supplier->name ?? '—' }} — {{ number_format($item->total, 2) }}</span>
                        @elseif($slug === 'users')
                            <span class="font-medium">{{ $item->name }}</span> <span class="text-gray-400">{{ $item->username }} · {{ $item->role }}</span>
                        @elseif($slug === 'stock-returns')
                            <span class="font-medium">{{ $item->type === 'from_customer' ? 'From' : 'To' }} {{ $item->type === 'from_customer' ? ($item->customer->name ?? 'Walk-in') : ($item->supplier->name ?? '—') }}</span>
                            <span class="text-gray-400">{{ $item->product->name ?? '—' }} × {{ $item->quantity }} — {{ number_format($item->quantity * $item->unit_price, 2) }}</span>
                        @elseif($slug === 'expenses')
                            <span class="font-medium">{{ $item->type === 'cash_in' ? 'Cash In' : 'Cash Out' }}{{ $item->category ? ' — '.$item->category : '' }}</span>
                            <span class="text-gray-400">{{ $item->expense_date->format('Y-m-d') }} — {{ number_format($item->amount, 2) }}</span>
                        @elseif($slug === 'customer-payments')
                            <span class="font-medium">{{ $item->type === 'credit' ? 'Credit' : 'Debit' }} — {{ $item->customer->name ?? '—' }}</span>
                            <span class="text-gray-400">{{ number_format($item->amount, 2) }}{{ $item->note ? ' — '.$item->note : '' }}</span>
                        @endif
                    </td>
                    <td class="p-3 text-gray-500">{{ $item->deleted_at->format('Y-m-d H:i') }}</td>
                    <td class="p-3 space-x-1 whitespace-nowrap">
                        <form method="POST" action="{{ route('trash.restore', $slug) }}" class="inline">
                            @csrf
                            <input type="hidden" name="ids[]" value="{{ $item->id }}">
                            <button class="btn btn-solid-green">Restore</button>
                        </form>
                        @if(auth()->user()->role === 'admin')
                        <form method="POST" action="{{ route('trash.force-delete', $slug) }}" class="inline confirm-submit" data-confirm-message="Permanently delete this? This cannot be undone.">
                            @csrf @method('DELETE')
                            <input type="hidden" name="ids[]" value="{{ $item->id }}">
                            <button class="btn btn-red">Permanently Delete</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>
@endforeach

<script>
document.querySelectorAll('.trash-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.trash-tab-btn').forEach(b => b.classList.replace('btn-dark', 'btn-gray'));
        btn.classList.replace('btn-gray', 'btn-dark');
        document.querySelectorAll('.trash-tab-panel').forEach(p => p.classList.add('hidden'));
        document.querySelector(`[data-trash-panel="${btn.dataset.trashTab}"]`).classList.remove('hidden');
    });
});
</script>
@endsection
