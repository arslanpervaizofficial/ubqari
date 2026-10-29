@extends('layouts.app')
@section('title', __('Receive Purchase Order'))
@section('content')
<h1 class="text-2xl font-bold mb-1">{{ __('Receive PO #') }}{{ $purchaseOrder->id }}</h1>
<p class="text-sm text-gray-500 mb-4">{{ __('Supplier') }}: {{ $purchaseOrder->supplier->name ?? __('— (deleted supplier)') }}</p>

<div class="mb-4 bg-blue-50 text-blue-800 text-sm px-4 py-2 rounded-lg">
    {{ __('Ordered quantity is pre-filled below — edit it if the supplier actually delivered more or less, then confirm. Stock is updated using what you enter here, not the original order quantity.') }}
</div>

<p class="text-sm text-gray-500 mb-4">{{ __('Batch No and Expiry Date are optional: leave Batch No blank and one is generated automatically (PO-{PO id}-L{line}); leave Expiry blank for stock with no expiry.') }}</p>

<form method="POST" action="{{ route('purchase-orders.receive', $purchaseOrder) }}" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full confirm-submit" data-confirm-message="{{ __('Confirm receiving this PO? Stock will be updated with the quantities entered below.') }}">
    @csrf

    <div class="overflow-x-auto">
    <table class="w-full text-sm mb-4">
        <thead class="text-left text-gray-500">
            <tr>
                <th class="py-2 pr-4">{{ __('Product') }}</th>
                <th class="px-4">{{ __('Current Stock') }}</th>
                <th class="px-4">{{ __('Ordered Qty') }}</th>
                <th class="px-4">{{ __('Received Qty') }}</th>
                <th class="px-4">{{ __('Batch No (optional)') }}</th>
                <th class="px-4">{{ __('Expiry Date (optional)') }}</th>
                <th class="px-4">{{ __('New Stock') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($purchaseOrder->items as $item)
            <tr class="border-t">
                <td class="py-2 pr-4 font-medium">{{ $item->product->name }} <span class="text-gray-400">({{ $item->product->sku }})</span></td>
                <td class="px-4 text-gray-500">{{ $item->product->stock }} {{ $item->product->unit }}</td>
                <td class="px-4">{{ $item->quantity }} {{ $item->product->unit }}</td>
                <td class="px-4">
                    <input type="number" step="0.01" min="0" required data-label="Received quantity"
                           name="items[{{ $item->id }}][received_quantity]" value="{{ $item->quantity }}"
                           class="po-received border rounded px-2 py-1 w-28" data-current-stock="{{ $item->product->stock }}" data-unit="{{ $item->product->unit }}">
                </td>
                <td class="px-4">
                    <input type="text" maxlength="255" placeholder="{{ __('Auto if blank') }}"
                           name="items[{{ $item->id }}][batch_no]"
                           class="border rounded px-2 py-1 w-32">
                </td>
                <td class="px-4">
                    <input type="date"
                           name="items[{{ $item->id }}][expiry_date]"
                           class="border rounded px-2 py-1">
                </td>
                <td class="px-4 po-new-stock font-medium text-green-700">{{ $item->product->stock + $item->quantity }} {{ $item->product->unit }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    {{-- H12b: receiving more than ordered is rejected unless this is ticked. --}}
    <label class="flex items-center gap-2 text-sm text-gray-600 mb-4">
        <input type="checkbox" name="allow_over_receive" value="1">
        {{ __('Over-shipment allow karein (kisi line ki received quantity ordered se zyada ho to bhi accept karein)') }}
    </label>

    <div class="flex justify-end gap-2">
        <a href="{{ route('purchase-orders.index') }}" class="btn btn-gray">{{ __('Cancel') }}</a>
        <button class="btn btn-solid-green">{{ __('Confirm Received') }}</button>
    </div>
</form>

<script>
document.querySelectorAll('.po-received').forEach(input => {
    input.addEventListener('input', () => {
        const tr = input.closest('tr');
        const current = parseFloat(input.dataset.currentStock) || 0;
        const unit = input.dataset.unit || '';
        const received = parseFloat(input.value) || 0;
        tr.querySelector('.po-new-stock').textContent = `${(current + received).toFixed(2)} ${unit}`;
    });
});
</script>
@endsection
