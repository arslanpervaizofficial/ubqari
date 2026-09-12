<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', \App\Models\AppSetting::current()->menu_title)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root { --accent: #c2703d; }

        body {
            background-color: #eef1f6;
        }

        /* Buttons and text-style inputs share one height (42px) so a
           "Filter"/"Save" button next to a text field always lines up,
           on every form in the app — not just the pages that happened to
           get a manual !min-h-[42px] patch before. */
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:4px; padding:8px 16px; min-height:42px; border-radius:8px; font-size:0.8rem; font-weight:600; transition:all .15s; line-height:1; }
        input[type=text], input[type=search], input[type=number], input[type=email],
        input[type=password], input[type=date], select { min-height:42px; }
        .btn-blue { background:#eff6ff; color:#1d4ed8; } .btn-blue:hover { background:#dbeafe; }
        .btn-gray { background:#f3f4f6; color:#374151; } .btn-gray:hover { background:#e5e7eb; }
        .btn-red { background:#fef2f2; color:#dc2626; } .btn-red:hover { background:#fee2e2; }
        .btn-green { background:#f0fdf4; color:#16a34a; } .btn-green:hover { background:#dcfce7; }
        .btn-yellow { background:#fffbeb; color:#b45309; } .btn-yellow:hover { background:#fef3c7; }
        .btn-dark { background:#111827; color:#fff; } .btn-dark:hover { background:#1f2937; }
        .btn-solid-green { background:#16a34a; color:#fff; } .btn-solid-green:hover { background:#15803d; }
        .btn-solid-blue { background:#2563eb; color:#fff; } .btn-solid-blue:hover { background:#1d4ed8; }
        .btn-solid-yellow { background:#eab308; color:#fff; } .btn-solid-yellow:hover { background:#ca8a04; }

        .sidebar-link { display:flex; align-items:center; gap:10px; padding:10px 16px; border-radius:8px; color:#cbd5e1; font-size:0.875rem; font-weight:500; transition: all .15s; }
        .sidebar-link:hover { background:rgba(255,255,255,0.08); color:#fff; }
        .sidebar-link.active { background:var(--accent); color:#fff; }

        #app-modal-overlay { backdrop-filter: blur(2px); }
        #app-modal-box { animation: modalPop .15s ease-out; }
        @keyframes modalPop { from { transform: scale(0.95); opacity:0; } to { transform: scale(1); opacity:1; } }

        @media print {
            body { background: #fff !important; }
        }
    </style>
</head>
<body class="min-h-screen">
@php($menuTitle = \App\Models\AppSetting::current()->menu_title)
@auth
<div class="flex min-h-screen">
    <!-- Sidebar backdrop (mobile only, click to close) -->
    <div id="sidebar-backdrop" class="hidden fixed inset-0 bg-black/40 z-30 md:hidden print:hidden"></div>

    <!-- Sidebar: fixed off-canvas drawer on mobile (slides in/out), static column on md+ -->
    <aside class="w-64 md:w-60 bg-slate-900 flex-shrink-0 flex flex-col print:hidden
                   fixed inset-y-0 left-0 z-40 -translate-x-full transition-transform duration-200 ease-out
                   md:static md:translate-x-0" id="sidebar">
        <div class="flex items-center justify-between px-5 py-5 border-b border-white/10">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <span class="text-white font-bold text-lg">{{ $menuTitle }}</span>
            </a>
            <button id="sidebar-close" class="md:hidden text-slate-400 hover:text-white text-xl leading-none">&times;</button>
        </div>
        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
            <a href="{{ route('pos.index') }}" class="sidebar-link {{ request()->routeIs('pos.index') ? 'active' : '' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l3-8H6.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m-10 0a2 2 0 100 4 2 2 0 000-4zm10 0a2 2 0 100 4 2 2 0 000-4z"/></svg>
                Billing
            </a>
            <a href="{{ route('pos.held-index') }}" class="sidebar-link {{ request()->routeIs('pos.held-*') ? 'active' : '' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
                Held Orders
            </a>
            @if(auth()->user()->role !== 'cashier')
                <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13h4v8H3v-8zM10 3h4v18h-4V3zM17 8h4v13h-4V8z"/></svg>
                    Dashboard
                </a>
                <a href="{{ route('products.index') }}" class="sidebar-link {{ request()->routeIs('products.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/></svg>
                    Products
                </a>
                <a href="{{ route('customers.index') }}" class="sidebar-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-4a4 4 0 100-8 4 4 0 000 8zm6 4a4 4 0 00-3-3.87m-9-8.13a4 4 0 100 8 4 4 0 000-8z"/></svg>
                    Customers
                </a>
                <a href="{{ route('suppliers.index') }}" class="sidebar-link {{ request()->routeIs('suppliers.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16V6a1 1 0 011-1h10a1 1 0 011 1v10m-12 0h12m-12 0a2 2 0 104 0m8 0a2 2 0 104 0m-4 0h4m0 0v-4h-4m0 0V8h3l3 4v4"/></svg>
                    Suppliers
                </a>
                <a href="{{ route('purchase-orders.index') }}" class="sidebar-link {{ request()->routeIs('purchase-orders.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l3-8H6.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m-10 0a2 2 0 100 4 2 2 0 000-4zm10 0a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    Purchase Orders
                </a>
                <a href="{{ route('orders.index') }}" class="sidebar-link {{ request()->routeIs('orders.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7h6m-6 4h6"/></svg>
                    Orders
                </a>
                <a href="{{ route('stock-returns.index') }}" class="sidebar-link {{ request()->routeIs('stock-returns.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                    Returns
                </a>
                <a href="{{ route('reports.index') }}" class="sidebar-link {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19V6l7-3v13M9 19l-6-2V8l6-2m0 13l7-3M4 8l5-2m7-3l5 2v13l-5-2"/></svg>
                    Reports
                </a>
                <a href="{{ route('trash.index') }}" class="sidebar-link {{ request()->routeIs('trash.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                    Trash
                </a>
            @endif
            @if(auth()->user()->role === 'admin')
                <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 11-4 0 2 2 0 014 0zM6 21v-2a4 4 0 014-4h0M15 11a4 4 0 014 4v.5M17.5 15.5L19 17l2.5-2.5M13 21v-2a4 4 0 00-4-4"/></svg>
                    Users
                </a>
                <a href="{{ route('backup.index') }}" class="sidebar-link {{ request()->routeIs('backup.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                    Backup
                </a>
            @endif
        </nav>
    </aside>

    <!-- Main column -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Topbar -->
        <header class="bg-white/90 backdrop-blur border-b flex items-center justify-between px-4 md:px-6 py-3 print:hidden">
            <button id="sidebar-toggle" class="md:hidden text-gray-600 w-8 h-8 flex items-center justify-center relative">
                <span id="sidebar-toggle-icon" class="text-xl leading-none">☰</span>
            </button>
            <span class="font-bold text-gray-800 md:hidden">{{ $menuTitle }}</span>
            <div class="hidden md:block"></div>

            <!-- User menu: click avatar/name to open Settings + Logout -->
            <div class="relative" id="user-menu">
                <button id="user-menu-btn" type="button" class="flex items-center gap-2 focus:outline-none">
                    <div class="w-8 h-8 rounded-full bg-slate-800 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </div>
                    <span class="text-sm text-gray-700 hidden sm:inline">{{ auth()->user()->name }} <span class="text-gray-400">({{ auth()->user()->role }})</span></span>
                    <svg class="w-4 h-4 text-gray-400 hidden sm:inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div id="user-menu-dropdown" class="hidden absolute right-0 mt-2 w-44 bg-white rounded-xl shadow-lg border py-1 z-50">
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Settings
                    </a>
                    @if(auth()->user()->role === 'admin')
                        <a href="{{ route('settings.edit') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                            Project Settings
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 5v1a3 3 0 01-3 3H6a3 3 0 01-3-3V6a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 flex-1 w-full min-w-0 print:p-0 overflow-x-hidden">
            @if(session('status'))
                <div class="mb-4 bg-green-100 text-green-800 px-4 py-2 rounded-lg print:hidden">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 bg-red-100 text-red-800 px-4 py-2 rounded-lg print:hidden">
                    <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
@else
    <main class="min-h-screen flex items-center justify-center p-4">
        @yield('content')
    </main>
@endauth

<!-- Global stylish modal (replaces browser alert/confirm everywhere) -->
<div id="app-modal-overlay" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div id="app-modal-box" class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6 text-center">
        <div id="app-modal-icon" class="text-3xl mb-2">💡</div>
        <p id="app-modal-message" class="text-gray-700 text-sm mb-5"></p>
        <div class="flex justify-center gap-2">
            <button id="app-modal-cancel" class="btn btn-gray hidden">Cancel</button>
            <button id="app-modal-ok" class="btn btn-dark">OK</button>
        </div>
    </div>
</div>

<script>
function uiAlert(message, icon = '💡') {
    return new Promise(resolve => {
        const overlay = document.getElementById('app-modal-overlay');
        document.getElementById('app-modal-icon').textContent = icon;
        document.getElementById('app-modal-message').textContent = message;
        document.getElementById('app-modal-cancel').classList.add('hidden');
        const okBtn = document.getElementById('app-modal-ok');
        overlay.classList.remove('hidden');
        const cleanup = () => { overlay.classList.add('hidden'); okBtn.removeEventListener('click', onOk); resolve(true); };
        const onOk = () => cleanup();
        okBtn.addEventListener('click', onOk);
    });
}

function uiConfirm(message, icon = '⚠️') {
    return new Promise(resolve => {
        const overlay = document.getElementById('app-modal-overlay');
        document.getElementById('app-modal-icon').textContent = icon;
        document.getElementById('app-modal-message').textContent = message;
        const cancelBtn = document.getElementById('app-modal-cancel');
        const okBtn = document.getElementById('app-modal-ok');
        cancelBtn.classList.remove('hidden');
        overlay.classList.remove('hidden');
        const cleanup = (result) => {
            overlay.classList.add('hidden');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            resolve(result);
        };
        const onOk = () => cleanup(true);
        const onCancel = () => cleanup(false);
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
    });
}

document.addEventListener('submit', function (e) {
    const form = e.target;
    if (form.classList && form.classList.contains('confirm-submit') && !form.dataset.confirmed) {
        e.preventDefault();
        const msg = form.dataset.confirmMessage || 'Are you sure?';
        uiConfirm(msg).then(ok => {
            if (ok) { form.dataset.confirmed = '1'; form.submit(); }
        });
    }
});

// ---------- Mobile sidebar: off-canvas drawer with backdrop + icon swap ----------
(function () {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const toggleBtn = document.getElementById('sidebar-toggle');
    const closeBtn = document.getElementById('sidebar-close');
    const icon = document.getElementById('sidebar-toggle-icon');
    if (!sidebar) return;

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        backdrop.classList.remove('hidden');
        if (icon) icon.textContent = '✕';
    }
    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
        if (icon) icon.textContent = '☰';
    }

    toggleBtn?.addEventListener('click', function () {
        sidebar.classList.contains('-translate-x-full') ? openSidebar() : closeSidebar();
    });
    closeBtn?.addEventListener('click', closeSidebar);
    backdrop?.addEventListener('click', closeSidebar);
    // Close automatically after navigating (mobile only)
    sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
        if (window.innerWidth < 768) closeSidebar();
    }));
})();

// ---------- Top-right user menu (Settings + Logout) ----------
(function () {
    const btn = document.getElementById('user-menu-btn');
    const dropdown = document.getElementById('user-menu-dropdown');
    if (!btn || !dropdown) return;
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });
    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && !btn.contains(e.target)) dropdown.classList.add('hidden');
    });
})();

// ---------- Custom form validation (replaces native browser validation bubbles) ----------
// Any <form novalidate> gets checked on submit: invalid fields get a red
// border + a small inline message right below them, instead of the
// browser's default balloon tooltip.
function fieldErrorMessage(input) {
    const v = input.validity;
    const label = input.dataset.label || input.name || 'This field';
    if (v.valueMissing) return `${label} is required.`;
    if (v.typeMismatch && input.type === 'email') return 'Please enter a valid email address.';
    if (v.rangeUnderflow) return `Value must be at least ${input.min}.`;
    if (v.rangeOverflow) return `Value must be at most ${input.max}.`;
    if (v.tooShort) return `Must be at least ${input.minLength} characters.`;
    if (v.patternMismatch) return input.dataset.patternMessage || `${label} format is invalid.`;
    return `${label} is invalid.`;
}

function showFieldError(input, message) {
    input.classList.add('border-red-500', 'ring-1', 'ring-red-300');
    let msgEl = input.parentElement.querySelector('.field-error-msg');
    if (!msgEl) {
        msgEl = document.createElement('p');
        msgEl.className = 'field-error-msg text-red-600 text-xs mt-1';
        input.insertAdjacentElement('afterend', msgEl);
    }
    msgEl.textContent = message;
}

function clearFieldError(input) {
    input.classList.remove('border-red-500', 'ring-1', 'ring-red-300');
    const msgEl = input.parentElement.querySelector('.field-error-msg');
    if (msgEl) msgEl.remove();
}

document.addEventListener('input', function (e) {
    if (e.target.matches('form[novalidate] input, form[novalidate] select, form[novalidate] textarea')) {
        if (e.target.checkValidity()) clearFieldError(e.target);
    }
});

document.addEventListener('submit', function (e) {
    const form = e.target;
    if (form.hasAttribute('novalidate') && !form.dataset.skipValidation) {
        const fields = form.querySelectorAll('input, select, textarea');
        let firstInvalid = null;
        fields.forEach(field => {
            if (!field.checkValidity()) {
                showFieldError(field, fieldErrorMessage(field));
                if (!firstInvalid) firstInvalid = field;
            } else {
                clearFieldError(field);
            }
        });
        if (firstInvalid) {
            e.preventDefault();
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus();
        }
    }
}, true);

document.addEventListener('click', async function (e) {
    const link = e.target.closest('[data-ajax-list] a');
    if (!link) return;
    e.preventDefault();
    const container = link.closest('[data-ajax-list]');
    const listId = container.getAttribute('data-ajax-list');
    container.style.opacity = '0.5';
    try {
        const res = await fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = listId
            ? doc.querySelector(`[data-ajax-list="${listId}"]`)
            : doc.querySelector('[data-ajax-list]');
        if (fresh) {
            container.innerHTML = fresh.innerHTML;
            window.history.pushState({}, '', link.href);
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            window.location.href = link.href;
        }
    } catch (err) {
        window.location.href = link.href;
    } finally {
        container.style.opacity = '1';
    }
});

// ---------- AJAX filter/search forms (no page reload, no "Filter" click needed) ----------
// Any <form data-ajax-filter="LIST_ID"> live-updates the matching
// [data-ajax-list="LIST_ID"] block as the user types/selects — same
// technique as the pagination handler above (the server always renders the
// same full page; we just swap in the one fragment we care about). The
// "Filter"/"Search" button still works too (progressive enhancement: if a
// fetch fails, or there's no matching list on the page, it falls back to a
// normal navigation).
function runAjaxFilter(form) {
    const listId = form.dataset.ajaxFilter;
    const container = document.querySelector(`[data-ajax-list="${listId}"]`);
    const action = form.getAttribute('action') || window.location.pathname;
    const qs = new URLSearchParams(new FormData(form)).toString();
    const url = qs ? `${action}?${qs}` : action;

    if (!container) { window.location.href = url; return; }

    container.style.opacity = '0.5';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const fresh = doc.querySelector(`[data-ajax-list="${listId}"]`);
            if (fresh) {
                container.innerHTML = fresh.innerHTML;
                window.history.pushState({}, '', url);
            } else {
                window.location.href = url;
            }
        })
        .catch(() => { window.location.href = url; })
        .finally(() => { container.style.opacity = '1'; });
}

document.addEventListener('submit', function (e) {
    if (e.target.matches('form[data-ajax-filter]')) {
        e.preventDefault();
        runAjaxFilter(e.target);
    }
});

document.querySelectorAll('form[data-ajax-filter]').forEach(form => {
    let debounce;
    form.querySelectorAll('input[type="text"], input[type="search"]').forEach(el => {
        el.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => runAjaxFilter(form), 400);
        });
    });
    form.querySelectorAll('select, input[type="date"], input[type="radio"], input[type="checkbox"]').forEach(el => {
        el.addEventListener('change', () => runAjaxFilter(form));
    });
});

// Quick-range buttons (e.g. "This Week") that only set date fields and
// should immediately re-run the ajax filter, instead of navigating away.
document.querySelectorAll('[data-ajax-quick-range]').forEach(btn => {
    btn.addEventListener('click', () => {
        const form = btn.closest('form[data-ajax-filter]');
        if (!form) return;
        const [from, to] = btn.dataset.ajaxQuickRange.split('|');
        form.querySelector('[name="from"]').value = from;
        form.querySelector('[name="to"]').value = to;
        runAjaxFilter(form);
    });
});

// ---------- Bulk select (checkbox column + "Delete Selected"/"Delete All" bars) ----------
// Row checkboxes: <input data-bulk-item="GROUP" value="ID">
// Header "select all":  <input data-bulk-select-all="GROUP">
// Live count/label:     <span data-bulk-count="GROUP"></span>
// "…Selected" button:   <button data-bulk-submit="GROUP" data-action-url="..." data-confirm-message="...">
// "…All" button:        <button data-bulk-all-submit data-action-url="..." data-confirm-message="...">
// Both buttons may also carry data-http-method="DELETE" for routes that need it
// (Laravel's usual _method spoofing — the browser still sends a POST).
function updateBulkBar(group) {
    const boxes = document.querySelectorAll(`input[data-bulk-item="${group}"]`);
    const checked = document.querySelectorAll(`input[data-bulk-item="${group}"]:checked`);
    document.querySelectorAll(`[data-bulk-count="${group}"]`).forEach(el => {
        el.textContent = checked.length ? `${checked.length} selected` : '';
    });
    document.querySelectorAll(`[data-bulk-submit="${group}"]`).forEach(btn => {
        btn.disabled = checked.length === 0;
        btn.classList.toggle('opacity-40', checked.length === 0);
        btn.classList.toggle('cursor-not-allowed', checked.length === 0);
    });
    document.querySelectorAll(`[data-bulk-select-all="${group}"]`).forEach(cb => {
        cb.checked = boxes.length > 0 && checked.length === boxes.length;
        cb.indeterminate = checked.length > 0 && checked.length < boxes.length;
    });
}

document.addEventListener('change', function (e) {
    if (e.target.matches('input[data-bulk-item]')) {
        updateBulkBar(e.target.dataset.bulkItem);
    }
    if (e.target.matches('input[data-bulk-select-all]')) {
        const group = e.target.dataset.bulkSelectAll;
        document.querySelectorAll(`input[data-bulk-item="${group}"]`).forEach(cb => { cb.checked = e.target.checked; });
        updateBulkBar(group);
    }
});

function submitBulkAction(url, group, httpMethod, keepQueryString) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url + (keepQueryString ? window.location.search : '');
    form.style.display = 'none';

    const csrf = document.createElement('input');
    csrf.type = 'hidden'; csrf.name = '_token';
    csrf.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
    form.appendChild(csrf);

    if (httpMethod && httpMethod !== 'POST') {
        const spoof = document.createElement('input');
        spoof.type = 'hidden'; spoof.name = '_method'; spoof.value = httpMethod;
        form.appendChild(spoof);
    }

    if (group) {
        document.querySelectorAll(`input[data-bulk-item="${group}"]:checked`).forEach(cb => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
            form.appendChild(inp);
        });
    }

    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('click', function (e) {
    const selBtn = e.target.closest('[data-bulk-submit]');
    if (selBtn) {
        e.preventDefault();
        const group = selBtn.dataset.bulkSubmit;
        const n = document.querySelectorAll(`input[data-bulk-item="${group}"]:checked`).length;
        if (!n) return;
        const msg = selBtn.dataset.confirmMessage || `Delete the ${n} selected item(s)?`;
        uiConfirm(msg).then(ok => {
            if (ok) submitBulkAction(selBtn.dataset.actionUrl, group, selBtn.dataset.httpMethod, false);
        });
        return;
    }
    const allBtn = e.target.closest('[data-bulk-all-submit]');
    if (allBtn) {
        e.preventDefault();
        const msg = allBtn.dataset.confirmMessage || 'Delete ALL items currently listed? This cannot be undone from here.';
        uiConfirm(msg).then(ok => {
            if (ok) submitBulkAction(allBtn.dataset.actionUrl, null, allBtn.dataset.httpMethod, true);
        });
    }
});
</script>

@auth
<script>
    (function () {
        const TIMEOUT_MS = 15 * 60 * 1000;
        let timer;
        function resetTimer() {
            clearTimeout(timer);
            timer = setTimeout(() => { document.getElementById('auto-logout-form').submit(); }, TIMEOUT_MS);
        }
        ['mousemove', 'keydown', 'click', 'scroll'].forEach(evt =>
            document.addEventListener(evt, resetTimer, { passive: true })
        );
        resetTimer();
    })();
</script>
<form id="auto-logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
@endauth
</body>
</html>
