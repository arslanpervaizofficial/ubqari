@extends('layouts.app')
@section('title', 'POS Billing')
@section('content')
<div class="flex justify-between items-center mb-4 flex-wrap gap-3">
    <h1 class="text-xl font-bold">
        Order: <span id="order-number">{{ $order->order_number }}</span>
        @if($order->original_completed_at)
            <span class="ml-2 align-middle px-2 py-1 rounded text-xs bg-amber-100 text-amber-700">Editing completed order</span>
        @endif
    </h1>
    <div class="flex items-center gap-2 flex-wrap">
        @if(in_array(auth()->user()->role, ['admin', 'manager']))
        <form method="POST" action="{{ route('pos.load-order') }}" class="flex items-center gap-1">
            @csrf
            <input type="text" name="order_number" placeholder="Previous order # to edit" required data-label="Order number"
                   class="border rounded px-3 py-2 text-sm w-48">
            <button class="btn btn-gray">Load Order</button>
        </form>
        @endif
        <form method="POST" action="{{ route('pos.new') }}" class="inline confirm-submit" data-confirm-message="Start a new order? Current one will be held automatically if it has items.">
            @csrf
            <button class="btn btn-solid-blue">+ New Order</button>
        </form>
    </div>
</div>

<div id="warning-banner" class="hidden mb-3 bg-amber-50 border border-amber-300 text-amber-800 text-sm px-4 py-2 rounded-lg"></div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 bg-white p-4 rounded-xl shadow-sm">
        <div class="flex gap-2 mb-3">
            <input id="product-search" type="text" placeholder="Scan barcode or search product..."
                   class="flex-1 border rounded px-3 py-2" autofocus>
        </div>
        <div id="search-results" class="mb-3 hidden bg-gray-50 border rounded max-h-56 overflow-y-auto"></div>

        <table class="w-full text-sm">
            <thead class="text-left border-b text-gray-500">
                <tr><th class="py-2">Product</th><th>Qty</th><th>Price</th><th>Disc %</th><th>Line Total</th><th></th></tr>
            </thead>
            <tbody id="cart-body"></tbody>
        </table>

        <div class="mt-4 border-t pt-4 flex flex-wrap justify-between items-end gap-4">
            <div class="flex gap-3">
                <div>
                    <label class="text-sm text-gray-600">Customer</label><br>
                    <select id="customer-select" class="border rounded px-3 py-2">
                        <option value="">Walk-in (no discount)</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" @selected($order->customer_id === $c->id)>{{ $c->name }} ({{ $c->discount_percent }}%)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-600">Overall Discount %</label><br>
                    <input type="number" step="0.01" id="overall-discount" value="{{ $order->discount_percent }}" class="border rounded px-3 py-2 w-24">
                </div>
            </div>
            <div class="text-right">
                <div class="text-sm">Subtotal: <span id="subtotal">{{ number_format($order->subtotal, 2) }}</span></div>
                <div class="text-sm">Item Discounts: -<span id="line-discount">{{ number_format($order->line_discount_total, 2) }}</span></div>
                <div class="text-sm">Overall Discount: -<span id="discount">{{ number_format($order->discount_amount, 2) }}</span></div>
                <div class="text-xl font-bold mt-1">Total: <span id="total">{{ number_format($order->total, 2) }}</span></div>
            </div>
        </div>

        <div class="mt-4 flex gap-2 justify-end">
            <form method="POST" action="{{ route('pos.quotation', $order) }}">
                @csrf
                <button class="btn btn-gray">Save as Draft/Quotation</button>
            </form>
            <button id="checkout-btn" class="btn btn-solid-green">{{ $order->original_completed_at ? 'Update Order' : 'Complete Order' }}</button>
        </div>
    </div>

    <div class="bg-white p-4 rounded-xl shadow-sm">
        <h2 class="font-semibold mb-2">Held Orders ({{ $heldOrders->count() }})</h2>
        <div class="space-y-2 max-h-96 overflow-y-auto">
            @forelse($heldOrders as $h)
                <div class="border rounded-lg p-2 flex justify-between items-center text-sm hover:bg-gray-50 transition">
                    <a href="{{ route('pos.held-detail', $h) }}" class="flex-1">
                        <div class="font-medium">{{ $h->order_number }}</div>
                        <div class="text-gray-500">{{ $h->customer->name ?? 'Walk-in' }} · {{ $h->items_count }} items</div>
                    </a>
                    <form method="POST" action="{{ route('pos.resume', $h) }}">
                        @csrf
                        <button class="btn btn-solid-yellow">Resume</button>
                    </form>
                </div>
            @empty
                <p class="text-gray-400 text-sm">No held orders.</p>
            @endforelse
        </div>
    </div>
</div>

<div id="checkout-modal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 w-full max-w-sm">
        <h3 class="font-bold mb-3">{{ $order->original_completed_at ? 'Update Payment' : 'Complete Payment' }}</h3>
        <form id="checkout-form" method="POST" action="{{ route('pos.complete', $order) }}">
            @csrf
            <label class="block text-sm mb-1">Payment Method</label>
            <select name="payment_method" id="payment-method" class="w-full border rounded px-3 py-2 mb-3">
                <option value="cash">Cash</option>
                <option value="bank">Bank Transfer</option>
                <option value="split">Split (Cash + Bank)</option>
            </select>

            <div id="single-amount">
                <label class="block text-sm mb-1">Amount Paid</label>
                <input type="number" step="0.01" name="paid_amount" class="w-full border rounded px-3 py-2 mb-3" value="{{ $order->total }}">
            </div>
            <div id="split-amounts" class="hidden">
                <label class="block text-sm mb-1">Cash Amount</label>
                <input type="number" step="0.01" name="cash_amount" class="w-full border rounded px-3 py-2 mb-3">
                <label class="block text-sm mb-1">Bank Amount</label>
                <input type="number" step="0.01" name="bank_amount" class="w-full border rounded px-3 py-2 mb-3">
            </div>
            <div id="bank-details" class="hidden">
                <label class="block text-sm mb-1">Bank Name</label>
                <input name="bank_name" placeholder="e.g. HBL, Meezan Bank" class="w-full border rounded px-3 py-2 mb-3">
                <label class="block text-sm mb-1">Transaction ID</label>
                <input name="transaction_id" placeholder="e.g. TXN123456" class="w-full border rounded px-3 py-2 mb-3">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" id="cancel-checkout" class="btn btn-gray">Cancel</button>
                <button class="btn btn-solid-green">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const INTEGER_UNITS = ['piece','pcs','dozen','box','pack','carton','unit'];
function isIntegerUnit(unit) { return INTEGER_UNITS.includes((unit||'').toLowerCase()); }

async function api(url, method = 'POST', body = null) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: body ? JSON.stringify(body) : null,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Something went wrong');
    return data;
}

function showWarning(msg) {
    const banner = document.getElementById('warning-banner');
    if (!msg) { banner.classList.add('hidden'); return; }
    banner.textContent = '💡 Tip: ' + msg;
    banner.classList.remove('hidden');
}

function renderOrder(order) {
    const tbody = document.getElementById('cart-body');
    tbody.innerHTML = '';
    order.items.forEach(item => {
        const unit = item.product.unit;
        const step = isIntegerUnit(unit) ? '1' : '0.01';
        const tr = document.createElement('tr');
        tr.className = 'border-b';
        tr.dataset.itemId = item.id;
        tr.innerHTML = `
            <td class="py-2">${item.product.name}<br><span class="text-xs text-gray-400">${unit}</span></td>
            <td><input type="number" step="${step}" min="${step}" value="${item.quantity}" data-item-id="${item.id}" class="qty-input w-20 border rounded px-2 py-1"></td>
            <td>${Number(item.unit_price).toFixed(2)}</td>
            <td><input type="number" step="0.01" min="0" max="100" value="${item.discount_percent}" data-item-id="${item.id}" class="disc-input w-16 border rounded px-2 py-1"></td>
            <td class="line-total font-medium">${Number(item.line_total).toFixed(2)}</td>
            <td><button class="remove-item text-red-600" data-item-id="${item.id}"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></td>`;
        tbody.appendChild(tr);
    });
    document.getElementById('subtotal').textContent = Number(order.subtotal).toFixed(2);
    document.getElementById('line-discount').textContent = Number(order.line_discount_total).toFixed(2);
    document.getElementById('discount').textContent = Number(order.discount_amount).toFixed(2);
    document.getElementById('total').textContent = Number(order.total).toFixed(2);
    document.getElementById('overall-discount').value = order.discount_percent;
    document.querySelector('#checkout-modal input[name="paid_amount"]').value = order.total;
}

renderOrder(@json($order));

let searchTimeout;
document.getElementById('product-search').addEventListener('input', function (e) {
    clearTimeout(searchTimeout);
    const q = e.target.value.trim();
    const box = document.getElementById('search-results');
    if (!q) { box.classList.add('hidden'); return; }
    searchTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`{{ route('pos.product-search') }}?q=${encodeURIComponent(q)}`, { headers: { 'Accept': 'application/json' } });
            const products = await res.json();
            box.innerHTML = '';
            products.forEach(p => {
                const div = document.createElement('div');
                div.className = 'p-2 hover:bg-gray-100 cursor-pointer flex justify-between text-sm';
                div.innerHTML = `<span>${p.name} (${p.sku})</span><span>${p.stock} ${p.unit} @ ${Number(p.sale_price).toFixed(2)}</span>`;
                div.onclick = () => addProduct(p.id);
                box.appendChild(div);
            });
            box.classList.remove('hidden');
        } catch (err) { console.error(err); }
    }, 250);
});

async function addProduct(productId) {
    try {
        const data = await api('{{ route("pos.add-item") }}', 'POST', { product_id: productId, quantity: 1 });
        renderOrder(data.order);
        document.getElementById('search-results').classList.add('hidden');
        document.getElementById('product-search').value = '';
        document.getElementById('product-search').focus();
    } catch (err) { uiAlert(err.message, "⚠️"); }
}

document.getElementById('cart-body').addEventListener('change', async function (e) {
    const itemId = e.target.dataset.itemId;
    if (!itemId) return;

    // 'change' fires on blur — which, if the user pressed Tab, happens
    // AFTER the browser already moved focus to the next field. renderOrder()
    // then rebuilds the whole table from scratch (tbody.innerHTML = ''),
    // destroying that freshly-focused element along with everything else,
    // so the Tab press visibly "loses" focus every time. Capture what's
    // focused right now and, once the new rows exist, refocus the matching
    // one (same item + same field type) so Tabbing between fields feels
    // normal instead of kicking focus out after every single field.
    const activeEl = document.activeElement;
    const activeClass = activeEl?.classList?.contains('qty-input') ? 'qty-input'
        : activeEl?.classList?.contains('disc-input') ? 'disc-input' : null;
    const activeItemId = activeEl?.dataset?.itemId;
    const selectionStart = activeEl?.selectionStart ?? null;

    function restoreFocus() {
        if (!activeClass || !activeItemId) return;
        const newEl = document.querySelector(`.${activeClass}[data-item-id="${activeItemId}"]`);
        if (newEl) {
            newEl.focus();
            if (selectionStart !== null && typeof newEl.setSelectionRange === 'function') {
                try { newEl.setSelectionRange(selectionStart, selectionStart); } catch (e) {}
            }
        }
    }

    try {
        if (e.target.classList.contains('qty-input')) {
            const data = await api(`/pos/item/${itemId}`, 'PATCH', { quantity: e.target.value });
            renderOrder(data.order);
            restoreFocus();
        } else if (e.target.classList.contains('disc-input')) {
            const data = await api(`/pos/item/${itemId}/discount`, 'PATCH', { discount_percent: e.target.value });
            renderOrder(data.order);
            showWarning(data.warning);
            restoreFocus();
        }
    } catch (err) { uiAlert(err.message, "⚠️"); }
});

document.getElementById('cart-body').addEventListener('click', async function (e) {
    // Use closest(), not a direct classList check — the button's clickable
    // area is mostly its inner <svg> icon, so e.target when actually
    // clicking the X is that <svg> (or its <path>), never the button
    // itself. A direct classList check on e.target silently missed every
    // click on the icon, which is why "Remove" looked like it didn't work.
    const btn = e.target.closest('.remove-item');
    if (!btn) return;
    const itemId = btn.dataset.itemId;
    try {
        const data = await api(`/pos/item/${itemId}`, 'DELETE');
        renderOrder(data.order);
    } catch (err) {
        // This request has no error path shown to the user at all before —
        // any failure (network hiccup, session/CSRF timeout, etc.) just
        // did nothing with zero feedback, which looks identical to "the
        // button doesn't work". Now it actually says why.
        uiAlert(err.message, "⚠️");
    }
});

document.getElementById('customer-select').addEventListener('change', async function (e) {
    const data = await api('{{ route("pos.set-customer") }}', 'POST', { customer_id: e.target.value || null });
    renderOrder(data.order);
});

document.getElementById('overall-discount').addEventListener('change', async function (e) {
    const data = await api('{{ route("pos.set-discount") }}', 'POST', { discount_percent: e.target.value });
    renderOrder(data.order);
});

document.getElementById('checkout-btn').addEventListener('click', () => {
    document.getElementById('checkout-modal').classList.remove('hidden');
});
document.getElementById('cancel-checkout').addEventListener('click', () => {
    document.getElementById('checkout-modal').classList.add('hidden');
});
document.getElementById('payment-method').addEventListener('change', function (e) {
    document.getElementById('single-amount').classList.toggle('hidden', e.target.value === 'split');
    document.getElementById('split-amounts').classList.toggle('hidden', e.target.value !== 'split');
    document.getElementById('bank-details').classList.toggle('hidden', e.target.value === 'cash');
});
</script>
@endsection
