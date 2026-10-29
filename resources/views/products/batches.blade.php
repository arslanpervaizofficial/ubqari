{{--
    v3.0 FEATURE 1 (Expiry + Batch Tracking) — batches section for a product.
    Included from products/edit.blade.php (there is no product show page:
    the products resource excepts 'show'). Display-only: it posts nothing,
    so it never affects the product update form above it.
    Expects $product. Safe on a not-yet-migrated install (renders nothing).
--}}
@if(\Illuminate\Support\Facades\Schema::hasTable('product_batches'))
<div class="bg-white p-6 rounded-xl shadow-sm w-full mt-6">
    <h2 class="text-lg font-bold mb-3">{{ __('Batches & Expiry') }}</h2>
    @php($batches = $product->batches()->fifo()->get())
    @if($batches->count())
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500">
            <tr>
                <th class="py-2 pr-4">{{ __('Batch No') }}</th>
                <th class="px-4">{{ __('Expiry Date') }}</th>
                <th class="px-4">{{ __('Quantity') }}</th>
                <th class="px-4">{{ __('Remaining') }}</th>
                <th class="px-4">{{ __('Purchase Order') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($batches as $batch)
            <tr class="border-t">
                <td class="py-2 pr-4 font-medium">{{ $batch->batch_no }}</td>
                <td class="px-4">{{ $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : '—' }}</td>
                <td class="px-4">{{ $batch->quantity }} {{ $product->unit }}</td>
                <td class="px-4 font-semibold">{{ $batch->remaining_quantity }} {{ $product->unit }}</td>
                <td class="px-4">{{ $batch->purchase_order_id ? __('PO #') . $batch->purchase_order_id : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <p class="text-xs text-gray-400 mt-2">{{ __('Batches are created when a purchase order is received, consumed earliest-expiry-first on sale, and topped up by customer returns. Product stock itself stays the authority.') }}</p>
    @else
    <p class="text-gray-400 text-sm">{{ __('No batches recorded for this product yet — stock received before batch tracking, or not yet received against a purchase order.') }}</p>
    @endif
</div>
@endif
