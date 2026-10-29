@extends('layouts.app')
@section('title', 'PO #' . $purchaseOrder->id)
@section('content')
<div class="flex justify-between items-center mb-1">
    <h1 class="text-2xl font-bold">{{ __('PO #') }}{{ $purchaseOrder->id }}</h1>
    <a href="{{ route('purchase-orders.index') }}" class="btn btn-gray"><svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> {{ __('Back') }}</a>
</div>
<p class="text-sm text-gray-500 mb-4">{{ __('Supplier') }}: {{ $purchaseOrder->supplier->name ?? __('— (deleted supplier)') }} {{ __('— Received') }}</p>

<div class="mb-4 bg-blue-50 text-blue-800 text-sm px-4 py-2 rounded-lg">
    {{ __('Click') }} <strong>{{ __('Still held (net of returns)') }}</strong> or <strong>{{ __('Discount %') }}</strong> {{ __('to correct a mistake — it saves automatically when you click away or press Enter. Stock and totals update live. "Still held" is what is actually left in stock from this line after any supplier returns, not the gross quantity that arrived. Product and Cost Price aren\'t editable here; those aren\'t corrections, they\'d be a different purchase.') }}
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full">
    <div class="overflow-x-auto">
    <table class="w-full text-sm mb-4" id="po-items-table">
        <thead class="text-left text-gray-500">
            <tr>
                <th class="py-2 pr-4">{{ __('Product') }}</th>
                <th class="px-4">{{ __('Ordered Qty') }}</th>
                <th class="px-4">{{ __('Still held') }} <span class="text-gray-400 font-normal">{{ __('(net of returns, click to edit)') }}</span></th>
                <th class="px-4">{{ __('Current Stock') }}</th>
                <th class="px-4">{{ __('Cost Price') }}</th>
                <th class="px-4">{{ __('Discount %') }} <span class="text-gray-400 font-normal">{{ __('(click to edit)') }}</span></th>
                <th class="px-4">{{ __('Line Total') }}</th>
                <th class="px-4">{{ __('Action') }}</th><!-- FIX 4: per-line delete -->
            </tr>
        </thead>
        <tbody>
        @foreach($purchaseOrder->items as $item)
            <tr class="border-t" data-item-id="{{ $item->id }}">
                <td class="py-2 pr-4 font-medium">{{ $item->product->name }} <span class="text-gray-400">({{ $item->product->sku }})</span></td>
                <td class="px-4 text-gray-500">{{ $item->quantity }} {{ $item->product->unit }}</td>
                <td class="px-4">
                    <span class="editable-field cursor-pointer border-b border-dashed border-gray-400 hover:bg-yellow-50 px-1"
                          data-field="received_quantity" data-value="{{ $item->received_quantity }}">{{ $item->received_quantity }}</span> {{ $item->product->unit }}
                </td>
                <td class="px-4 text-gray-500 po-current-stock">{{ $item->product->stock }} {{ $item->product->unit }}</td>
                <td class="px-4 text-gray-500">{{ number_format($item->cost_price, 2) }}</td>
                <td class="px-4">
                    <span class="editable-field cursor-pointer border-b border-dashed border-gray-400 hover:bg-yellow-50 px-1"
                          data-field="discount_percent" data-value="{{ $item->discount_percent }}">{{ $item->discount_percent }}</span>%
                </td>
                <td class="px-4 font-medium po-line-total">{{ number_format($item->received_quantity * $item->net_cost_price, 2) }}</td>
                <td class="px-4 whitespace-nowrap">
                    @if($item->can_delete && Route::has('purchase-orders.items.destroy'))
                        <form method="POST" action="{{ route('purchase-orders.items.destroy', [$purchaseOrder, $item]) }}"
                              onsubmit="return confirm('Ye line delete kar di jaye gi aur stock {{ $item->received_quantity }} {{ $item->product->unit }} se kam ho jaye ga. Continue karein?');"
                              class="inline">
                            @csrf @method('DELETE')
                            <button class="btn btn-red">{{ __('Delete') }}</button>
                        </form>
                    @elseif($item->can_delete)
                        <button class="btn btn-gray" disabled title="{{ $item->delete_block_reason ?? 'Delete route abhi add nahi hui' }}">{{ __('Delete') }}</button>
                    @else
                        <button class="btn btn-gray" disabled title="{{ $item->delete_block_reason }}">{{ __('Delete') }}</button>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <div class="flex justify-end">
        <div class="text-right space-y-1">
            <div class="text-gray-500 text-sm">{{ __('Subtotal (after each item\'s own discount)') }}: <span class="font-medium text-gray-800" id="po-subtotal">{{ number_format($purchaseOrder->subtotal, 2) }}</span></div>
            <div class="text-gray-500 text-sm">
                {{ __('Overall Discount %') }}:
                <span class="editable-field cursor-pointer border-b border-dashed border-gray-400 hover:bg-yellow-50 px-1" id="po-overall-discount" data-value="{{ $purchaseOrder->discount_percent }}">{{ $purchaseOrder->discount_percent }}</span>%
                (<span id="po-discount-amount">{{ number_format($purchaseOrder->discount_amount, 2) }}</span>)
            </div>
            <div class="text-xl font-bold">{{ __('Total') }}: <span id="po-total">{{ number_format($purchaseOrder->total, 2) }}</span></div>
            <div class="text-gray-500 text-sm">{{ __('Total Products') }}: {{ $purchaseOrder->items->count() }} &nbsp;·&nbsp; Total Received Quantity: {{ $purchaseOrder->items->sum('received_quantity') }}</div>
        </div>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

async function patch(url, body) {
    const res = await fetch(url, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.message || 'Could not save that change.');
    }
    return res.json();
}

function applyPoTotals(po) {
    document.getElementById('po-subtotal').textContent = Number(po.subtotal).toFixed(2);
    document.getElementById('po-discount-amount').textContent = Number(po.discount_amount).toFixed(2);
    document.getElementById('po-total').textContent = Number(po.total).toFixed(2);
}

// Turns a display <span data-value="..."> into an <input>, saves via PATCH
// on blur/Enter, and swaps back to a span showing the new value — the
// "click to edit, click away to save" pattern used across this field type.
function makeEditable(span, { onSave }) {
    span.addEventListener('click', function handler() {
        if (span.querySelector('input')) return; // already editing
        const current = span.dataset.value;
        const input = document.createElement('input');
        input.type = 'number';
        input.step = '0.01';
        input.min = '0';
        input.value = current;
        input.className = 'border rounded px-2 py-1 w-20 text-sm';
        span.textContent = '';
        span.appendChild(input);
        input.focus();
        input.select();

        let saved = false;
        async function save() {
            if (saved) return;
            saved = true;
            const newValue = input.value;
            if (newValue === '' || isNaN(newValue)) {
                span.textContent = current;
                span.dataset.value = current;
                return;
            }
            try {
                await onSave(newValue, span);
                span.dataset.value = newValue;
                span.textContent = newValue;
            } catch (err) {
                uiAlert(err.message, '⚠️');
                span.textContent = current;
                span.dataset.value = current;
            }
        }

        input.addEventListener('blur', save);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { saved = true; span.textContent = current; span.dataset.value = current; }
        });
    });
}

document.querySelectorAll('#po-items-table tr[data-item-id]').forEach(tr => {
    const itemId = tr.dataset.itemId;
    const url = `{{ url('purchase-orders/' . $purchaseOrder->id . '/items') }}/${itemId}`;

    tr.querySelectorAll('.editable-field').forEach(span => {
        const field = span.dataset.field;
        makeEditable(span, {
            onSave: async (newValue) => {
                const data = await patch(url, { [field]: newValue });
                tr.querySelector('.po-line-total').textContent = Number(data.line_total).toFixed(2);
                tr.querySelector('.po-current-stock').textContent = Number(data.product_stock).toFixed(2) + ' ' + tr.querySelector('.po-current-stock').textContent.replace(/[\d.\s]/g, '');
                applyPoTotals(data.po);
            },
        });
    });
});

makeEditable(document.getElementById('po-overall-discount'), {
    onSave: async (newValue) => {
        const data = await patch(`{{ route('purchase-orders.update-discount', $purchaseOrder) }}`, { discount_percent: newValue });
        applyPoTotals(data);
    },
});
</script>
@endsection
