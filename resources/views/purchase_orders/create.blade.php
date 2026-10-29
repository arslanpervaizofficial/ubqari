@extends('layouts.app')
@section('title', __('New Purchase Order'))
@section('content')
<h1 class="text-2xl font-bold mb-4">{{ __('New Purchase Order') }}</h1>

@if(count($preselectedLines))
<div class="mb-4 bg-blue-50 text-blue-800 text-sm px-4 py-2 rounded-lg print:hidden">
    {{ __('Pre-filled from your stock alert — adjust quantity/price as needed, then create the order.') }}
</div>
@endif

<!-- Print-only estimate (hidden on screen, shown only when printing) -->
<div id="po-print-area" class="hidden print:block bg-white p-6">
    <div class="flex justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold">{{ \App\Models\AppSetting::current()->print_title }}</h1>
            <p class="text-sm text-gray-500">{{ __('PURCHASE ORDER — ESTIMATE') }}</p>
        </div>
        <div class="text-right text-sm">
            <div>{{ __('Date') }}: <span id="po-print-date"></span></div>
        </div>
    </div>
    <p class="mb-2 text-sm">{{ __('Supplier') }}: <span id="po-print-supplier"></span></p>
    <table class="w-full text-sm mb-4">
        <thead class="border-b text-left"><tr><th class="py-1">{{ __('Product') }}</th><th>{{ __('Qty') }}</th><th>{{ __('Cost') }}</th><th>{{ __('Disc') }}</th><th>{{ __('Line Total') }}</th></tr></thead>
        <tbody id="po-print-items"></tbody>
    </table>
    <div class="text-right text-sm space-y-1">
        <div>{{ __('Subtotal') }}: <span id="po-print-subtotal"></span></div>
        <div>{{ __('Overall Discount (') }}<span id="po-print-discount-pct"></span>%): -<span id="po-print-discount"></span></div>
        <div class="font-bold text-lg">{{ __('Estimated Total') }}: <span id="po-print-total"></span></div>
    </div>
    <p class="text-xs text-gray-400 mt-4">{{ __('This is an estimate only, generated before saving — not a confirmed Purchase Order until "Create Purchase Order" is clicked.') }}</p>
</div>

<!-- FIX 1 — session-expire warning (autosave keeps a browser copy, so a
     logout can no longer wipe typed data; this banner only appears
     when the server session actually expired mid-typing) -->
<div id="session-warning" class="mb-4 bg-yellow-100 text-yellow-800 text-sm px-4 py-2 rounded-lg" style="display:none;">
    {{ __('Session expire ho gai hai. Ghabrayein nahi — aapka data is browser me save hai. Dobara login karein, phir') }} <strong>{{ __('Restore Draft') }}</strong> dabayen.
</div>
<div id="restore-banner" class="mb-4 bg-blue-50 text-blue-800 text-sm px-4 py-2 rounded-lg" style="display:none;">
    {{ __('Pichla draft is browser se restore kar diya gaya hai. Check kar ke') }} <strong>{{ __('Save Draft') }}</strong> ya <strong>{{ __('Create Purchase Order') }}</strong> dabayen.
</div>

<form id="po-form" method="POST" action="{{ route('purchase-orders.store') }}" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full print:hidden">
    @csrf
    <input type="hidden" name="draft_id" id="draft_id" value="{{ old('draft_id', $draft->id ?? '') }}">
    <div class="mb-4 max-w-sm">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Supplier') }}</label>
        <select name="supplier_id" required data-label="Supplier" class="w-full border rounded px-3 py-2">
            @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto">
    <table class="w-full text-sm mb-2">
        <thead class="text-left text-gray-500">
            <tr>
                <th class="py-1 pr-2">#</th><!-- FIX 3: index number -->
                <th class="py-1 pr-4">{{ __('Product') }}</th>
                <th class="px-4">{{ __('Remaining Stock') }}</th>
                <th class="px-4">{{ __('Quantity') }}</th>
                <th class="px-4">{{ __('New Stock') }}</th>
                <th class="px-4">{{ __('Cost Price') }}</th>
                <th class="px-4">{{ __('Disc %') }}<br><span class="text-xs text-gray-400 normal-case">{{ __('(supplier gives us)') }}</span></th>
                <th class="px-4">{{ __('Max Discount %') }}<br><span class="text-xs text-gray-400 normal-case">{{ __('(billing limit — updates product)') }}</span></th>
                <th class="px-4">{{ __('Line Total') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="po-items"></tbody>
    </table>
    </div>
    <button type="button" id="add-line" class="text-sm text-blue-600 mb-4">+ {{ __('Add another product') }}</button>

    <!-- FIX 3: live totals — lines added + sum of all quantities -->
    <div id="po-totals-summary" class="mb-4 text-sm text-gray-600">
        {{ __('Total Products') }}: <strong id="po-total-products">0</strong>
        &nbsp;·&nbsp; Total Quantity: <strong id="po-total-qty">0</strong>
    </div>

    <div class="border-t pt-4 flex flex-col items-end gap-3">
        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600">{{ __('Overall Discount %') }} <span class="text-gray-400">{{ __('(special/company discount on whole order)') }}</span></label>
            <input type="number" step="0.01" min="0" max="100" id="po-overall-discount" name="discount_percent" value="0"
                   class="border rounded px-3 py-2 w-24 text-right">
        </div>
        <div class="text-right text-sm text-gray-500">
            {{ __('Subtotal') }}: <span id="po-subtotal">0.00</span>
            &nbsp;·&nbsp; Discount: -<span id="po-discount-amount">0.00</span>
        </div>
        <div class="text-right">
            <div class="text-gray-500 text-sm">{{ __('Estimated Order Total') }}</div>
            <div class="text-2xl font-bold" id="po-total">0.00</div>
        </div>
    </div>

    <div class="mt-4 flex gap-2">
        <button class="btn btn-dark" id="po-create-btn">{{ __('Create Purchase Order') }}</button>
        <!-- FIX 1: temporary save only — NO final order, NO stock change -->
        <button type="button" id="po-save-draft" class="btn btn-gray">{{ __('Save Draft') }}</button>
        <button type="button" id="po-restore-draft" class="btn btn-gray">{{ __('Restore Draft') }}</button>
        <button type="button" id="po-print-btn" class="btn btn-gray">
            <svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m0-10V5a2 2 0 012-2h6a2 2 0 012 2v2m-10 0h10m-10 5h.01M7 17h10v4H7v-4z"/></svg>
            {{ __('Print Estimate') }}
        </button>
    </div>
</form>

<script>
// Built entirely in the controller and passed as a plain variable — the
// expression being echoed here has no commas and no closures, so it cannot
// be mis-parsed the way the old inline-array version could be.
const PRODUCTS = @json($productsForJs);
const PRESELECTED_LINES = @json($preselectedLines);
// FIX 1/2 — priority for the rows this page opens with: a failed submit's
// old input first (so NOTHING typed is lost), then a server draft, then
// the stock-alert preselection. Draft lines use the same field names as
// old input (quantity / cost_price), preselected lines use qty / cost.
const OLD_LINES = @json(old('items'));
const DRAFT_LINES = @json($draftLines ?? []);
const DRAFT_ROUTE = "{{ Route::has('purchase-orders.draft') ? route('purchase-orders.draft') : url('purchase-orders/draft') }}";
const CURRENT_USER_ID = {{ auth()->id() ?? 0 }};
const SEARCH_URL = "{{ route('products.ajax-search') }}";
let lineCount = 0;
let searchSeq = 0;

// One shared registry of "row -> its floating results box" instead of each
// row wiring up its own document-level click/scroll/resize listener — with,
// say, 15 line items that used to mean 45 duplicate global listeners doing
// the same cheap check. A single listener here does the same job for all of
// them, and each row's entry is dropped when the row is removed.
const searchWidgets = [];

// FIX 2 — ONE delegated remove handler for the whole table, instead of a
// listener bound inside addLine(). That is what made the cross vanish
// after a validation round-trip: rows that were not created by addLine()
// on this page load had no listener at all. Delegation works on every
// row, however it got onto the page.
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.remove-line');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr || !tr.closest('#po-items')) return;
    const widgetIdx = searchWidgets.findIndex(w => w.tr === tr);
    if (widgetIdx >= 0) {
        searchWidgets[widgetIdx].resultsBox.remove();
        searchWidgets.splice(widgetIdx, 1);
    }
    const rows = document.querySelectorAll('#po-items tr');
    if (rows.length <= 1) {
        // Keep one blank row instead of an empty table.
        tr.querySelectorAll('input').forEach(el => el.value = '');
        const idInput = tr.querySelector('.po-product-id');
        if (idInput) { idInput.value = ''; delete idInput.dataset.stock; delete idInput.dataset.unit; }
    } else {
        tr.remove();
    }
    recalc();
    saveDraftToBrowser();
    scheduleAutosave();
});
document.addEventListener('click', (e) => {
    searchWidgets.forEach(({ tr, resultsBox }) => {
        if (!tr.contains(e.target) && !resultsBox.contains(e.target)) resultsBox.classList.add('hidden');
    });
});
// capture:true also catches scroll events from the overflow-x-auto table
// wrapper, which — unlike window scroll — don't bubble.
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

function findLocalProduct(id) {
    return PRODUCTS.find(p => String(p.id) === String(id));
}

/** Debounced AJAX product search — the dropdown only queries the server once
 *  the user has typed 2+ characters, and results replace the list live
 *  without any page reload/navigation. */
async function searchProducts(q) {
    if (q.trim().length < 2) return PRODUCTS.slice(0, 15);
    const mySeq = ++searchSeq;
    try {
        const res = await fetch(`${SEARCH_URL}?q=${encodeURIComponent(q)}`, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (mySeq !== searchSeq) return null; // a newer search superseded this one
        return data;
    } catch (e) {
        return [];
    }
}

function addLine(productId = null, qty = '', cost = null, focusSearch = false, discount = null, maxDiscount = null) {
    const i = lineCount++;
    const preset = productId ? findLocalProduct(productId) : null;

    const tr = document.createElement('tr');
    tr.className = 'border-t align-middle';
    tr.innerHTML = `
        <td class="py-2 pr-2 text-gray-500 po-row-index"></td><!-- FIX 3: index -->
        <td class="py-2 pr-4 relative" style="min-width:220px">
            <input type="hidden" name="items[${i}][product_id]" class="po-product-id" value="${productId ?? ''}" required>
            <input type="text" autocomplete="off" placeholder="Type 2+ letters to search product..."
                   class="po-product-search border rounded px-2 py-1 w-full" value="${preset ? preset.name + ' (' + preset.sku + ')' : ''}">
        </td>
        <td class="px-4 po-remaining-stock text-gray-500 whitespace-nowrap">0</td>
        <td class="px-4"><input type="number" step="0.01" min="0" name="items[${i}][quantity]" value="${qty}" required class="po-qty border rounded px-2 py-1 w-24"></td>
        <td class="px-4 po-new-stock font-medium text-green-700 whitespace-nowrap">0</td>
        <td class="px-4"><input type="number" step="0.01" min="0" name="items[${i}][cost_price]" value="${cost ?? (preset ? preset.cost : '')}" required class="po-cost border rounded px-2 py-1 w-28"></td>
        <td class="px-4"><input type="number" step="0.01" min="0" max="100" name="items[${i}][discount_percent]" value="${discount ?? (preset ? preset.discount : 0)}" class="po-discount border rounded px-2 py-1 w-20"></td>
        <td class="px-4"><input type="number" step="0.01" min="0" max="100" name="items[${i}][max_discount_percent]" value="${maxDiscount ?? (preset ? preset.max_discount : 0)}" class="po-max-discount border rounded px-2 py-1 w-20"></td>
        <td class="px-4 po-line-total font-medium">0.00</td>
        <td><button type="button" class="remove-line text-red-600"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></td>`;
    // New lines go to the TOP, not the bottom — same "reverse" system as
    // billing's cart, so the row you're about to fill in next is always
    // right there next to the "+ Add another product" control instead of
    // requiring a scroll down as the order grows.
    const poItemsBody = document.getElementById('po-items');
    poItemsBody.insertBefore(tr, poItemsBody.firstChild);

    // dataset used by recalc() for remaining/new stock + unit display
    const idInput = tr.querySelector('.po-product-id');
    if (preset) {
        idInput.dataset.stock = preset.stock;
        idInput.dataset.unit = preset.unit;
    }

    const searchInput = tr.querySelector('.po-product-search');
    const qtyInput = tr.querySelector('.po-qty');
    const costInput = tr.querySelector('.po-cost');
    const discountInput = tr.querySelector('.po-discount');
    const maxDiscountInput = tr.querySelector('.po-max-discount');

    // The results dropdown is deliberately NOT nested inside the table.
    // The table sits in an `overflow-x-auto` wrapper (needed so the wide
    // table can scroll sideways on small screens), and per the CSS spec,
    // setting overflow-x on an element forces overflow-y to a non-visible
    // value too — so anything absolutely positioned inside it (like this
    // dropdown) gets clipped to the table's own box instead of floating
    // over the page, which is what showed up as "results appear inside a
    // scrollbar instead of as a dropdown". Appending it to <body> with
    // `position: fixed`, positioned in JS from the input's on-screen
    // coordinates, escapes that clipping entirely regardless of which
    // ancestor scrolls.
    const resultsBox = document.createElement('div');
    resultsBox.className = 'po-product-results hidden fixed z-50 bg-white border rounded shadow-lg max-h-56 overflow-y-auto';
    document.body.appendChild(resultsBox);

    function positionResultsBox() {
        const rect = searchInput.getBoundingClientRect();
        resultsBox.style.left = `${rect.left}px`;
        resultsBox.style.top = `${rect.bottom + 4}px`;
        resultsBox.style.width = `${rect.width}px`;
    }

    function showResultsBox() {
        positionResultsBox();
        resultsBox.classList.remove('hidden');
    }

    const widget = { tr, resultsBox, reposition: positionResultsBox };
    searchWidgets.push(widget);

    // Mirrors the billing page's search-dropdown state (currentResults +
    // highlightedIndex) but scoped per-row here, since each PO line has its
    // own independent product search box rather than one shared one.
    let currentResults = [];
    let highlightedIndex = -1;

    function focusAndSelect(el) {
        if (!el) return;
        el.focus();
        if (el.select) el.select();
    }

    function highlightResult(index) {
        const items = resultsBox.querySelectorAll('.po-result-item');
        items.forEach(el => el.classList.remove('bg-blue-100'));
        if (index >= 0 && index < items.length) {
            items[index].classList.add('bg-blue-100');
            items[index].scrollIntoView({ block: 'nearest' });
        }
        highlightedIndex = index;
    }

    function selectProduct(p) {
        idInput.value = p.id;
        idInput.dataset.stock = p.stock;
        idInput.dataset.unit = p.unit;
        searchInput.value = `${p.name} (${p.sku})`;
        if (cost === null) costInput.value = p.cost;
        discountInput.value = p.discount ?? 0;
        maxDiscountInput.value = p.max_discount ?? 0;
        resultsBox.classList.add('hidden');
        highlightedIndex = -1;
        currentResults = [];
        recalc();
    }

    function renderResults(list) {
        if (list === null) return; // stale response, ignore
        currentResults = list || [];
        highlightedIndex = -1;
        if (!list.length) {
            resultsBox.innerHTML = `<div class="p-2 text-gray-400 text-sm">No matching products.</div>`;
        } else {
            resultsBox.innerHTML = list.map(p =>
                `<div class="po-result-item p-2 hover:bg-gray-100 cursor-pointer text-sm flex justify-between" data-id="${p.id}">
                    <span>${p.name} (${p.sku})</span><span class="text-gray-400">${p.stock} ${p.unit}</span>
                 </div>`
            ).join('');
            resultsBox.querySelectorAll('.po-result-item').forEach(el => {
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
        idInput.value = ''; // typing invalidates the previous selection until they pick again
        clearTimeout(debounce);
        const q = searchInput.value;
        debounce = setTimeout(async () => {
            const list = await searchProducts(q);
            renderResults(list);
        }, 250);
    });
    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 2 || !idInput.value) {
            searchProducts(searchInput.value).then(renderResults);
        }
    });

    // Lets a product line be filled out without ever touching the mouse:
    // type to search, Arrow Up/Down to move through the dropdown, Enter (or
    // Tab) to pick the highlighted result and jump straight to Quantity.
    searchInput.addEventListener('keydown', (e) => {
        const dropdownOpen = !resultsBox.classList.contains('hidden') && currentResults.length;
        if (dropdownOpen) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightResult(Math.min(highlightedIndex + 1, currentResults.length - 1));
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightResult(Math.max(highlightedIndex - 1, 0));
                return;
            }
            if (e.key === 'Escape') {
                resultsBox.classList.add('hidden');
                highlightedIndex = -1;
                return;
            }
            if (e.key === 'Enter' || (e.key === 'Tab' && !e.shiftKey)) {
                e.preventDefault();
                const pick = highlightedIndex >= 0 ? currentResults[highlightedIndex] : currentResults[0];
                if (pick) selectProduct(pick);
                focusAndSelect(qtyInput);
                return;
            }
        } else if (e.key === 'Enter') {
            // No dropdown showing (product already picked) — Enter still
            // advances to Quantity, same as Tab already does natively.
            e.preventDefault();
            focusAndSelect(qtyInput);
        }
    });

    tr.querySelector('.po-qty').addEventListener('input', recalc);
    tr.querySelector('.po-cost').addEventListener('input', recalc);
    tr.querySelector('.po-discount').addEventListener('input', recalc);

    // Enter moves Qty → Cost → Discount → Max Discount, same as Tab. From
    // the last field, Enter or Tab adds a fresh row instead of leaving the
    // line — the "add another product" step never needs the mouse either.
    qtyInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); focusAndSelect(costInput); }
    });
    costInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); focusAndSelect(discountInput); }
    });
    discountInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); focusAndSelect(maxDiscountInput); }
    });
    maxDiscountInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || (e.key === 'Tab' && !e.shiftKey)) {
            e.preventDefault();
            addLine(null, '', null, true);
        }
    });

    recalc();

    // Land the cursor straight in the new (top) row's product search box —
    // used both for the "+ Add another product" button and for the
    // keyboard-driven "finish last field → new row" flow above.
    if (focusSearch) searchInput.focus();
}

function recalc() {
    let subtotal = 0;
    // FIX 3: index numbers (DOM order = display order; new rows go on top)
    // plus total lines and total quantity, updated on every change.
    let totalProducts = 0;
    let totalQty = 0;
    document.querySelectorAll('#po-items tr').forEach((tr, idx) => {
        const indexCell = tr.querySelector('.po-row-index');
        if (indexCell) indexCell.textContent = idx + 1;
        const idInput = tr.querySelector('.po-product-id');
        const currentStock = parseFloat(idInput?.dataset.stock ?? 0);
        const unit = idInput?.dataset.unit ?? '';
        const qty = parseFloat(tr.querySelector('.po-qty').value) || 0;
        const cost = parseFloat(tr.querySelector('.po-cost').value) || 0;
        const discount = parseFloat(tr.querySelector('.po-discount').value) || 0;
        const netCost = cost * (1 - discount / 100);
        const lineTotal = qty * netCost;

        tr.querySelector('.po-remaining-stock').textContent = `${currentStock} ${unit}`;
        tr.querySelector('.po-new-stock').textContent = `${(currentStock + qty).toFixed(2)} ${unit}`;
        tr.querySelector('.po-line-total').textContent = lineTotal.toFixed(2);
        subtotal += lineTotal;
        const hasProduct = tr.querySelector('.po-product-id')?.value;
        if (hasProduct || qty || cost) { totalProducts++; totalQty += qty; }
    });
    const totalProductsEl = document.getElementById('po-total-products');
    const totalQtyEl = document.getElementById('po-total-qty');
    if (totalProductsEl) totalProductsEl.textContent = totalProducts;
    if (totalQtyEl) totalQtyEl.textContent = totalQty;

    const overallDiscount = parseFloat(document.getElementById('po-overall-discount').value) || 0;
    const discountAmount = subtotal * (overallDiscount / 100);
    const total = subtotal - discountAmount;

    document.getElementById('po-subtotal').textContent = subtotal.toFixed(2);
    document.getElementById('po-discount-amount').textContent = discountAmount.toFixed(2);
    document.getElementById('po-total').textContent = total.toFixed(2);
}

document.getElementById('add-line').addEventListener('click', () => addLine(null, '', null, true));
document.getElementById('po-overall-discount').addEventListener('input', recalc);

/** Builds the hidden #po-print-area from the current (unsaved) form state
 *  and prints it — text is inserted via textContent, never innerHTML, so a
 *  product name with special characters can't break the markup. Runs
 *  entirely client-side since there's no PO record yet to print from. */
document.getElementById('po-print-btn').addEventListener('click', function () {
    document.getElementById('po-print-date').textContent = new Date().toISOString().slice(0, 10);

    const supplierSelect = document.querySelector('select[name="supplier_id"]');
    document.getElementById('po-print-supplier').textContent =
        supplierSelect.options[supplierSelect.selectedIndex]?.text ?? '';

    const tbody = document.getElementById('po-print-items');
    tbody.innerHTML = '';
    document.querySelectorAll('#po-items tr').forEach(tr => {
        const cells = [
            tr.querySelector('.po-product-search')?.value || '(not selected)',
            tr.querySelector('.po-qty')?.value || '0',
            tr.querySelector('.po-cost')?.value || '0',
            (tr.querySelector('.po-discount')?.value || '0') + '%',
            tr.querySelector('.po-line-total')?.textContent || '0.00',
        ];
        const row = document.createElement('tr');
        row.className = 'border-b';
        cells.forEach(text => {
            const td = document.createElement('td');
            td.className = 'py-1';
            td.textContent = text;
            row.appendChild(td);
        });
        tbody.appendChild(row);
    });

    document.getElementById('po-print-subtotal').textContent = document.getElementById('po-subtotal').textContent;
    document.getElementById('po-print-discount-pct').textContent = document.getElementById('po-overall-discount').value || '0';
    document.getElementById('po-print-discount').textContent = document.getElementById('po-discount-amount').textContent;
    document.getElementById('po-print-total').textContent = document.getElementById('po-total').textContent;

    window.print();
});

// FIX 1/2 — initial rows: old input (after a failed submit) beats a
// server draft, which beats the stock-alert preselection. Each addLine()
// prepends to the top, so iterate in reverse to keep the original order.
const INITIAL_LINES = (OLD_LINES && Object.values(OLD_LINES).length)
    ? Object.values(OLD_LINES)
    : (DRAFT_LINES && DRAFT_LINES.length ? DRAFT_LINES : PRESELECTED_LINES);
if (INITIAL_LINES.length) {
    [...INITIAL_LINES].reverse().forEach(line => addLine(
        line.product_id ?? null,
        line.qty ?? line.quantity ?? '',
        line.cost ?? line.cost_price ?? null,
        false,
        line.discount ?? line.discount_percent ?? null,
        line.max_discount ?? line.max_discount_percent ?? null
    ));
} else {
    addLine(); // one blank starter row
}

// ================= FIX 1: draft autosave + browser copy =================
const poForm = document.getElementById('po-form');
const draftIdInput = document.getElementById('draft_id');
const draftStatusEl = document.getElementById('draft-status') || {}; // v3.0.5: hint line removed, fallback keeps status writes harmless
const STORAGE_KEY = 'ubqari_po_draft_' + CURRENT_USER_ID;
let sessionExpired = false;
let autosaveTimer = null;

function collectDraftRows() {
    const rows = [];
    document.querySelectorAll('#po-items tr').forEach(tr => {
        const productId = tr.querySelector('.po-product-id')?.value || '';
        const quantity = tr.querySelector('.po-qty')?.value || '';
        const cost = tr.querySelector('.po-cost')?.value || '';
        const discount = tr.querySelector('.po-discount')?.value || '';
        const maxDiscount = tr.querySelector('.po-max-discount')?.value || '';
        if (productId || quantity || cost) {
            rows.push({ product_id: productId, quantity, cost_price: cost, discount_percent: discount, max_discount_percent: maxDiscount });
        }
    });
    return rows;
}
function saveDraftToBrowser() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            draft_id: draftIdInput.value,
            supplier_id: document.querySelector('select[name="supplier_id"]')?.value || '',
            discount_percent: document.getElementById('po-overall-discount')?.value || '',
            items: collectDraftRows(),
            saved_at: new Date().toISOString(),
        }));
    } catch (e) {}
}
function restoreFromBrowser(showBanner) {
    let saved;
    try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { return; }
    if (!saved || !saved.items || !saved.items.length) return;
    // Remove current rows (with their floating dropdowns) first.
    document.querySelectorAll('#po-items tr').forEach(tr => {
        const widgetIdx = searchWidgets.findIndex(w => w.tr === tr);
        if (widgetIdx >= 0) { searchWidgets[widgetIdx].resultsBox.remove(); searchWidgets.splice(widgetIdx, 1); }
        tr.remove();
    });
    // addLine() prepends, so add in reverse to keep the saved order.
    [...saved.items].reverse().forEach(item => addLine(
        item.product_id || null, item.quantity || '', item.cost_price || null, false,
        item.discount_percent ?? null, item.max_discount_percent ?? null
    ));
    if (saved.supplier_id) { const s = document.querySelector('select[name="supplier_id"]'); if (s) s.value = saved.supplier_id; }
    if (saved.discount_percent !== undefined) { const d = document.getElementById('po-overall-discount'); if (d) d.value = saved.discount_percent; }
    if (saved.draft_id) draftIdInput.value = saved.draft_id;
    recalc();
    if (showBanner) document.getElementById('restore-banner').style.display = 'block';
}
function scheduleAutosave() {
    clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(() => sendDraft(false), 1000);
}
async function sendDraft(manual) {
    if (sessionExpired && !manual) return; // local copy only until re-login
    const token = document.querySelector('meta[name="csrf-token"]')?.content
        || poForm.querySelector('input[name="_token"]')?.value;
    draftStatusEl.textContent = 'Draft save ho raha hai...';
    try {
        const res = await fetch(DRAFT_ROUTE, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            body: new FormData(poForm),
        });
        if (res.status === 419) {
            sessionExpired = true;
            document.getElementById('session-warning').style.display = 'block';
            draftStatusEl.textContent = 'Session expire — draft browser me safe hai.';
            return;
        }
        const json = await res.json().catch(() => null);
        if (json && json.draft_id) draftIdInput.value = json.draft_id;
        sessionExpired = false;
        document.getElementById('session-warning').style.display = 'none';
        saveDraftToBrowser();
        draftStatusEl.textContent = (json && json.message) ? json.message + (json.saved_at ? ' • ' + json.saved_at : '') : 'Draft saved' + (json && json.saved_at ? ' • ' + json.saved_at : '');
    } catch (e) {
        // Offline / network error: the browser copy is still safe.
        draftStatusEl.textContent = 'Draft browser me save hai (server se connect nahi hua).';
        saveDraftToBrowser();
    }
}
document.getElementById('po-save-draft').addEventListener('click', () => sendDraft(true));
document.getElementById('po-restore-draft').addEventListener('click', () => {
    if (collectDraftRows().length && !confirm('Current form ka data browser draft se replace ho jaye ga. Continue karein?')) return;
    restoreFromBrowser(true);
});
document.getElementById('po-items').addEventListener('input', () => { saveDraftToBrowser(); scheduleAutosave(); });
document.getElementById('po-items').addEventListener('change', () => { saveDraftToBrowser(); scheduleAutosave(); });
document.querySelector('select[name="supplier_id"]')?.addEventListener('change', () => { saveDraftToBrowser(); scheduleAutosave(); });
document.getElementById('po-overall-discount')?.addEventListener('input', () => { saveDraftToBrowser(); scheduleAutosave(); });

// Auto-restore ONLY when this page opened with a single blank row and no
// server draft / old input / preselection — i.e. the "I logged back in
// and everything was gone" case. The server draft (if any) already won
// above, so this never overwrites it.
(function autoRestore() {
    const rows = document.querySelectorAll('#po-items tr');
    const blank = rows.length === 1
        && !rows[0].querySelector('.po-product-id')?.value
        && !rows[0].querySelector('.po-qty')?.value
        && !rows[0].querySelector('.po-cost')?.value
        && !draftIdInput.value;
    if (blank) restoreFromBrowser(true);
})();

// ================= FIX 2: submit guard =================
// Blank rows never reach the server (they used to fail validation and
// wipe the form); partial rows stop the submit in place with their row
// number, instead of a server round-trip that lost everything.
let poSubmitting = false;
poForm.addEventListener('submit', (e) => {
    if (poSubmitting) { e.preventDefault(); return; }
    const rows = [...document.querySelectorAll('#po-items tr')];
    let firstBad = null;
    rows.forEach((tr, idx) => {
        const product = tr.querySelector('.po-product-id')?.value || '';
        const qty = tr.querySelector('.po-qty')?.value || '';
        const cost = tr.querySelector('.po-cost')?.value || '';
        const empty = !product && !qty && !cost;
        const partial = !empty && (!product || !qty || !cost);
        tr.classList.remove('bg-red-100');
        if (empty) {
            tr.dataset.skip = '1';
        } else if (partial) {
            tr.classList.add('bg-red-100');
            if (!firstBad) firstBad = { tr, idx };
        }
    });
    if (firstBad) {
        e.preventDefault();
        alert('Row ' + (firstBad.idx + 1) + ' adhoori hai: product, quantity aur cost teeno bharein, ya row ko cross (×) se delete karein.');
        firstBad.tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const emptyInput = [...firstBad.tr.querySelectorAll('input')].find(el => !el.value);
        if (emptyInput) emptyInput.focus();
        return;
    }
    rows.filter(tr => tr.dataset.skip === '1').forEach(tr => {
        const widgetIdx = searchWidgets.findIndex(w => w.tr === tr);
        if (widgetIdx >= 0) { searchWidgets[widgetIdx].resultsBox.remove(); searchWidgets.splice(widgetIdx, 1); }
        tr.remove();
    });
    if (!document.querySelector('#po-items tr')) {
        e.preventDefault();
        alert('Kam az kam aik product lazmi hai.');
        return;
    }
    recalc();
    poSubmitting = true;
    const createBtn = document.getElementById('po-create-btn');
    if (createBtn) { createBtn.disabled = true; createBtn.textContent = 'Creating...'; }
    // NOTE: localStorage is NOT cleared here — if the server still
    // rejects the submit, the browser copy must survive. It is cleared
    // by the PO list page after a successful create (see patch notes).
});
</script>
@endsection
