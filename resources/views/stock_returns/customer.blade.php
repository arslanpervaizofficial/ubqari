@extends('layouts.app')
@section('title', 'Return from Customer')
@section('content')
<h1 class="text-2xl font-bold mb-4">Return from Customer</h1>
<p class="text-gray-500 text-sm mb-4">Adds the returned quantity back to stock for each item below. If linked to a customer, reduces what they owe by the return's net value (after discount).</p>

<div class="bg-white p-4 rounded-xl shadow-sm w-full mb-4">
    <label class="block text-sm text-gray-600 mb-1">Load Previous Order (optional)</label>
    <p class="text-xs text-gray-400 mb-2">Type the order number or ID from the original sale — its items, prices and discount load in below automatically, ready to adjust.</p>
    <div class="flex gap-2 max-w-md">
        <input type="text" id="load-order-number" placeholder="e.g. ORD-20260910-ABCDE" class="flex-1 border rounded px-3 py-2">
        <button type="button" id="load-order-btn" class="btn btn-solid-blue whitespace-nowrap">Load Order</button>
    </div>
    <p id="load-order-error" class="text-red-600 text-sm mt-2 hidden"></p>
    <p id="load-order-success" class="text-green-700 text-sm mt-2 hidden"></p>
</div>

<form method="POST" action="{{ route('stock-returns.customer-store') }}" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf
    <div class="mb-4 max-w-sm">
        <label class="block text-sm text-gray-600 mb-1">Customer (optional)</label>
        <select name="customer_id" id="customer-select" class="w-full border rounded px-3 py-2">
            <option value="">— Not tied to a specific customer —</option>
            @foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto">
    <table class="w-full text-sm mb-2">
        <thead class="text-left text-gray-500">
            <tr>
                <th class="py-1 pr-4">Product</th>
                <th class="px-4">Current Stock</th>
                <th class="px-4">Quantity Returned</th>
                <th class="px-4">New Stock</th>
                <th class="px-4">Unit Price</th>
                <th class="px-4">Discount %</th>
                <th class="px-4">Reason</th>
                <th class="px-4">Net Line Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="sr-items"></tbody>
    </table>
    </div>
    <button type="button" id="add-line" class="text-sm text-blue-600 mb-4">+ Add another product</button>

    @error('quantity') <p class="text-red-600 text-sm mb-4">{{ $message }}</p> @enderror

    <div class="border-t pt-4 flex justify-end">
        <div class="text-right">
            <div class="text-gray-500 text-sm">Total Return Value (after discount)</div>
            <div class="text-2xl font-bold" id="sr-total">0.00</div>
        </div>
    </div>

    <div class="mt-4">
        <button class="btn btn-solid-green">Record Return</button>
    </div>
</form>

<script>
// Same product-search pattern as the Purchase Order form: a local list for
// instant filtering while typing, backed by the AJAX search endpoint once
// the user has typed 2+ characters.
const PRODUCTS = {!! json_encode($productsForJs) !!};
const SEARCH_URL = "{{ route('products.ajax-search') }}";
const LOOKUP_ORDER_URL = "{{ route('stock-returns.lookup-order') }}";
let lineCount = 0;
let searchSeq = 0;
const searchWidgets = [];

document.addEventListener('click', (e) => {
    searchWidgets.forEach(({ tr, resultsBox }) => {
        if (!tr.contains(e.target) && !resultsBox.contains(e.target)) resultsBox.classList.add('hidden');
    });
});
document.addEventListener('scroll', () => {
    searchWidgets.forEach(({ resultsBox, reposition }) => {
        if (!resultsBox.classList.contains('hidden')) reposition();
    });
}, true);
window.addEventListener('resize', () => {
    searchWidgets.forEach(({ resultsBox, reposition }) => {
        if (!resultsBox.classList.contains('hidden')) reposition();
    });
});

async function searchProducts(q) {
    if (q.trim().length < 2) return PRODUCTS.slice(0, 15);
    const mySeq = ++searchSeq;
    try {
        const res = await fetch(`${SEARCH_URL}?q=${encodeURIComponent(q)}`, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (mySeq !== searchSeq) return null;
        return data;
    } catch (e) {
        return [];
    }
}

/**
 * Adds one return line.
 * @param {object|null} preset - when loading from a previous order:
 *   { order_item_id, product_id, name, sku, unit, stock, unit_price, discount_percent, reason, max_qty, qty }
 *   preset.locked = true makes the product field read-only (it's a known,
 *   real sold item — not something to re-search) and shows a "from order"
 *   badge. Manually-added lines (preset = null) keep the searchable field
 *   and an editable discount box, per the "manual product also needs a
 *   discount option" requirement.
 */
function addLine(preset = null) {
    const i = lineCount++;
    const locked = !!(preset && preset.locked);

    const tr = document.createElement('tr');
    tr.className = 'border-t align-top';
    tr.innerHTML = `
        <td class="py-2 pr-4 relative" style="min-width:220px">
            <input type="hidden" name="items[${i}][product_id]" class="sr-product-id" value="${preset ? preset.product_id : ''}">
            <input type="hidden" name="items[${i}][order_item_id]" class="sr-order-item-id" value="${preset && preset.order_item_id ? preset.order_item_id : ''}">
            ${locked
                ? `<div class="flex items-center gap-2">
                       <span class="sr-product-label font-medium">${preset.name} (${preset.sku})</span>
                       <span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full whitespace-nowrap">from order</span>
                   </div>`
                : `<input type="text" autocomplete="off" placeholder="Type 2+ letters to search product..." class="sr-product-search border rounded px-2 py-1 w-full">`}
        </td>
        <td class="py-2 px-4 sr-current-stock text-gray-500 whitespace-nowrap">—</td>
        <td class="py-2 px-4"><input type="number" step="0.01" min="0.01" ${preset ? `max="${preset.max_qty}"` : ''} name="items[${i}][quantity]" required value="${preset ? preset.qty : ''}" class="sr-qty border rounded px-2 py-1 w-24"></td>
        <td class="py-2 px-4 sr-new-stock font-medium text-green-700 whitespace-nowrap">—</td>
        <td class="py-2 px-4"><input type="number" step="0.01" min="0" name="items[${i}][unit_price]" required value="${preset ? preset.unit_price : ''}" class="sr-price border rounded px-2 py-1 w-28"></td>
        <td class="py-2 px-4">
            <input type="number" step="0.01" min="0" max="100" name="items[${i}][discount_percent]" value="${preset ? preset.discount_percent : 0}" ${locked ? 'readonly title="Carried over from the original sale"' : 'placeholder="0"'} class="sr-discount border rounded px-2 py-1 w-20 ${locked ? 'bg-gray-50 text-gray-600' : ''}">
            ${locked ? `<div class="sr-discount-breakdown text-xs text-gray-400 mt-1 whitespace-nowrap"></div>` : ''}
        </td>
        <td class="py-2 px-4"><input type="text" name="items[${i}][reason]" placeholder="e.g. Wrong item" value="${preset && preset.reason ? preset.reason : ''}" class="sr-reason border rounded px-2 py-1 w-36"></td>
        <td class="py-2 px-4 sr-line-total font-medium">0.00</td>
        <td class="py-2"><button type="button" class="remove-line text-red-600" title="Remove — this product will not be included in the return"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></td>`;
    document.getElementById('sr-items').appendChild(tr);

    const idInput = tr.querySelector('.sr-product-id');
    const priceInput = tr.querySelector('.sr-price');

    if (preset) {
        idInput.dataset.stock = preset.stock;
        idInput.dataset.unit = preset.unit;

        const breakdown = tr.querySelector('.sr-discount-breakdown');
        if (breakdown) {
            const parts = [];
            if (preset.item_discount_percent > 0) parts.push(`item ${preset.item_discount_percent}%`);
            if (preset.order_discount_percent > 0) parts.push(`overall ${preset.order_discount_percent}%`);
            breakdown.textContent = parts.length ? `(${parts.join(' + ')} = ${preset.discount_percent}%)` : 'no discount on sale';
        }
    }

    let widget = null;

    if (!locked) {
        const searchInput = tr.querySelector('.sr-product-search');
        const resultsBox = document.createElement('div');
        resultsBox.className = 'sr-product-results hidden fixed z-50 bg-white border rounded shadow-lg max-h-56 overflow-y-auto';
        document.body.appendChild(resultsBox);

        function positionResultsBox() {
            const rect = searchInput.getBoundingClientRect();
            resultsBox.style.left = `${rect.left}px`;
            resultsBox.style.top = `${rect.bottom + 4}px`;
            resultsBox.style.width = `${rect.width}px`;
        }
        function showResultsBox() { positionResultsBox(); resultsBox.classList.remove('hidden'); }

        widget = { tr, resultsBox, reposition: positionResultsBox };
        searchWidgets.push(widget);

        function selectProduct(p) {
            idInput.value = p.id;
            idInput.dataset.stock = p.stock;
            idInput.dataset.unit = p.unit;
            searchInput.value = `${p.name} (${p.sku})`;
            priceInput.value = p.price;
            resultsBox.classList.add('hidden');
            recalc();
        }

        function renderResults(list) {
            if (list === null) return;
            if (!list.length) {
                resultsBox.innerHTML = `<div class="p-2 text-gray-400 text-sm">No matching products.</div>`;
            } else {
                resultsBox.innerHTML = list.map(p =>
                    `<div class="sr-result-item p-2 hover:bg-gray-100 cursor-pointer text-sm flex justify-between" data-id="${p.id}">
                        <span>${p.name} (${p.sku})</span><span class="text-gray-400">${p.stock} ${p.unit}</span>
                     </div>`
                ).join('');
                resultsBox.querySelectorAll('.sr-result-item').forEach(el => {
                    el.addEventListener('click', () => {
                        const p = list.find(x => String(x.id) === el.dataset.id);
                        if (p) selectProduct(p);
                    });
                });
            }
            showResultsBox();
        }

        let debounce;
        searchInput.addEventListener('input', () => {
            idInput.value = '';
            clearTimeout(debounce);
            const q = searchInput.value;
            debounce = setTimeout(async () => { renderResults(await searchProducts(q)); }, 250);
        });
        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim().length >= 2 || !idInput.value) {
                searchProducts(searchInput.value).then(renderResults);
            }
        });
    }

    tr.querySelector('.sr-qty').addEventListener('input', recalc);
    tr.querySelector('.sr-price').addEventListener('input', recalc);
    tr.querySelector('.sr-discount').addEventListener('input', recalc);
    tr.querySelector('.remove-line').addEventListener('click', () => {
        if (widget) {
            searchWidgets.splice(searchWidgets.indexOf(widget), 1);
            widget.resultsBox.remove();
        }
        tr.remove();
        recalc();
    });
    recalc();
}

function recalc() {
    let total = 0;
    document.querySelectorAll('#sr-items tr').forEach(tr => {
        const idInput = tr.querySelector('.sr-product-id');
        const currentStock = idInput?.dataset.stock !== undefined ? parseFloat(idInput.dataset.stock) : null;
        const unit = idInput?.dataset.unit ?? '';
        const qty = parseFloat(tr.querySelector('.sr-qty').value) || 0;
        const price = parseFloat(tr.querySelector('.sr-price').value) || 0;
        const discountPct = parseFloat(tr.querySelector('.sr-discount').value) || 0;
        const gross = qty * price;
        const netLineTotal = gross - (gross * discountPct / 100);

        tr.querySelector('.sr-current-stock').textContent = currentStock !== null ? `${currentStock} ${unit}` : '—';
        tr.querySelector('.sr-new-stock').textContent = currentStock !== null ? `${(currentStock + qty).toFixed(2)} ${unit}` : '—';
        tr.querySelector('.sr-line-total').textContent = netLineTotal.toFixed(2);
        total += netLineTotal;
    });

    document.getElementById('sr-total').textContent = total.toFixed(2);
}

document.getElementById('add-line').addEventListener('click', () => addLine());

// --- Load Previous Order ---
const loadBtn = document.getElementById('load-order-btn');
const loadInput = document.getElementById('load-order-number');
const loadError = document.getElementById('load-order-error');
const loadSuccess = document.getElementById('load-order-success');

async function loadOrder() {
    const orderNumber = loadInput.value.trim();
    loadError.classList.add('hidden');
    loadSuccess.classList.add('hidden');
    if (!orderNumber) return;

    loadBtn.disabled = true;
    loadBtn.textContent = 'Loading...';
    try {
        const res = await fetch(`${LOOKUP_ORDER_URL}?order_number=${encodeURIComponent(orderNumber)}`, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!res.ok) {
            loadError.textContent = data.error || 'Could not load that order.';
            loadError.classList.remove('hidden');
            return;
        }

        // Loading an order starts a fresh return for that transaction — any
        // existing rows are cleared so items from two different sales never
        // get mixed into one submission under a single customer_id.
        document.querySelectorAll('#sr-items tr').forEach(tr => tr.remove());
        searchWidgets.splice(0).forEach(w => w.resultsBox.remove());

        document.getElementById('customer-select').value = data.customer_id ?? '';

        data.items.forEach(item => addLine({
            locked: true,
            order_item_id: item.order_item_id,
            product_id: item.product_id,
            name: item.name,
            sku: item.sku,
            unit: item.unit,
            stock: item.stock,
            unit_price: item.unit_price,
            discount_percent: item.discount_percent,
            item_discount_percent: item.item_discount_percent,
            order_discount_percent: item.order_discount_percent,
            max_qty: item.remaining_qty,
            qty: item.remaining_qty,
        }));

        loadSuccess.textContent = `Loaded ${data.items.length} item(s) from order ${data.order_number}${data.customer_name ? ' — customer: ' + data.customer_name : ' (walk-in sale)'}. Adjust quantity or remove any you don't want to return.`;
        loadSuccess.classList.remove('hidden');
    } catch (e) {
        loadError.textContent = 'Something went wrong loading that order.';
        loadError.classList.remove('hidden');
    } finally {
        loadBtn.disabled = false;
        loadBtn.textContent = 'Load Order';
    }
}

loadBtn.addEventListener('click', loadOrder);
loadInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); loadOrder(); } });

addLine(); // one blank starter row
</script>
@endsection
