@php $p = $product ?? null; @endphp
<div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Name</label>
        <input name="name" value="{{ old('name', $p->name ?? '') }}" required data-label="Name" class="w-full border rounded px-3 py-2">
        @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">SKU</label>
        <input name="sku" value="{{ old('sku', $p->sku ?? '') }}" required data-label="SKU" class="w-full border rounded px-3 py-2">
        @error('sku') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Barcode</label>
        <div class="flex gap-2">
            <input id="barcode-input" name="barcode" value="{{ old('barcode', $p->barcode ?? '') }}" class="w-full border rounded px-3 py-2">
            <button type="button" id="generate-barcode-btn" class="btn btn-blue whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Auto
        </button>
        </div>
        @error('barcode') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Category</label>
        <select id="category-select" name="category" class="w-full border rounded px-3 py-2">
            <option value="">— None —</option>
            @foreach($categories as $cat)
                <option value="{{ $cat }}" @selected(old('category', $p->category ?? '') === $cat)>{{ $cat }}</option>
            @endforeach
            <option value="__new__">+ Add new category…</option>
        </select>
        <input id="new-category-input" type="text" name="new_category" value="{{ old('new_category') }}" placeholder="New category name"
               class="w-full border rounded px-3 py-2 mt-2 hidden">
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Unit</label>
        <select name="unit" required data-label="Unit" class="w-full border rounded px-3 py-2">
            @foreach(['piece'=>'Piece','kg'=>'Kilogram (kg)','g'=>'Gram (g)','ml'=>'Milliliter (ml)','liter'=>'Liter','dozen'=>'Dozen','box'=>'Box','pack'=>'Pack','carton'=>'Carton','meter'=>'Meter'] as $val=>$label)
                <option value="{{ $val }}" @selected(old('unit', $p->unit ?? 'piece') === $val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Purchase Price <span class="text-gray-400">(supplier's rate, before their discount)</span></label>
        <input type="number" step="0.01" min="0" id="purchase-price-input" name="purchase_price" value="{{ old('purchase_price', $p->purchase_price ?? 0) }}" required data-label="Purchase Price" class="w-full border rounded px-3 py-2">
        @error('purchase_price') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Purchase Discount % <span class="text-gray-400">(what the supplier gives us on cost)</span></label>
        <input type="number" step="0.01" min="0" max="100" id="purchase-discount-input" name="purchase_discount_percent" value="{{ old('purchase_discount_percent', $p->purchase_discount_percent ?? 0) }}" class="w-full border rounded px-3 py-2">
        @error('purchase_discount_percent') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Net Purchase Price <span class="text-gray-400">(after supplier discount — what Stock Value/COGS use)</span></label>
        <input type="text" id="net-purchase-price-display" disabled value="{{ number_format(($p->purchase_price ?? 0) * (1 - ($p->purchase_discount_percent ?? 0) / 100), 2) }}" class="w-full border rounded px-3 py-2 bg-gray-100 text-gray-600">
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Sale Price</label>
        <input type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price', $p->sale_price ?? 0) }}" required data-label="Sale Price" class="w-full border rounded px-3 py-2">
        @error('sale_price') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Max Discount % (billing limit)</label>
        <input type="number" step="0.01" min="0" max="100" name="max_discount_percent" value="{{ old('max_discount_percent', $p->max_discount_percent ?? 0) }}" class="w-full border rounded px-3 py-2">
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Current Stock</label>
        <input type="number" step="0.01" min="0" name="stock" value="{{ old('stock', $p->stock ?? 0) }}" required data-label="Current Stock" class="w-full border rounded px-3 py-2">
        @error('stock') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Min Stock</label>
        <input type="number" step="0.01" min="0" name="min_stock" value="{{ old('min_stock', $p->min_stock ?? '') }}" class="w-full border rounded px-3 py-2">
    </div>
    <div class="flex flex-col justify-end">
        <label class="block text-sm text-gray-600 mb-1">Max Stock</label>
        <input type="number" step="0.01" min="0" name="max_stock" value="{{ old('max_stock', $p->max_stock ?? '') }}" class="w-full border rounded px-3 py-2">
    </div>
</div>

<script>
document.getElementById('generate-barcode-btn').addEventListener('click', async function () {
    const res = await fetch('{{ route("products.generate-barcode") }}', { headers: { 'Accept': 'application/json' } });
    const data = await res.json();
    document.getElementById('barcode-input').value = data.barcode;
});

const categorySelect = document.getElementById('category-select');
const newCategoryInput = document.getElementById('new-category-input');
function toggleNewCategory() {
    const isNew = categorySelect.value === '__new__';
    newCategoryInput.classList.toggle('hidden', !isNew);
    if (!isNew) newCategoryInput.value = '';
}
categorySelect.addEventListener('change', toggleNewCategory);
if (newCategoryInput.value) { categorySelect.value = '__new__'; toggleNewCategory(); }

const purchasePriceInput = document.getElementById('purchase-price-input');
const purchaseDiscountInput = document.getElementById('purchase-discount-input');
const netPurchasePriceDisplay = document.getElementById('net-purchase-price-display');
function updateNetPurchasePrice() {
    const price = parseFloat(purchasePriceInput.value) || 0;
    const discount = parseFloat(purchaseDiscountInput.value) || 0;
    const net = price * (1 - discount / 100);
    netPurchasePriceDisplay.value = net.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
purchasePriceInput.addEventListener('input', updateNetPurchasePrice);
purchaseDiscountInput.addEventListener('input', updateNetPurchasePrice);
</script>
