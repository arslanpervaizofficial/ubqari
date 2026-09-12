@extends('layouts.app')
@section('title', 'Products')
@section('content')
<div class="flex justify-between items-center mb-4 flex-wrap gap-2">
    <h1 class="text-2xl font-bold">Products</h1>
    <div class="flex items-center gap-2">
        <a href="{{ route('products.export') }}" class="btn btn-gray">
            <svg class="w-4 h-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Export Price List
        </a>
        <form method="POST" action="{{ route('products.import') }}" enctype="multipart/form-data" class="flex items-center gap-2">
            @csrf
            <button type="button" class="btn btn-gray" onclick="document.getElementById('import-file-input').click()">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                Choose File
            </button>
            <span id="import-file-name" class="text-sm text-gray-500 max-w-[130px] truncate">No file chosen</span>
            <input type="file" id="import-file-input" name="file" accept=".xlsx" required data-label="Price list file" class="hidden">
            <button class="btn btn-dark whitespace-nowrap">Import</button>
        </form>
        <a href="{{ route('products.create') }}" class="btn btn-dark">+ Add Product</a>
    </div>
</div>
@error('file') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

<script>
document.getElementById('import-file-input').addEventListener('change', function () {
    document.getElementById('import-file-name').textContent = this.files.length ? this.files[0].name : 'No file chosen';
});
</script>

<div class="flex gap-2 mb-4">
    <a href="{{ route('products.index') }}" class="btn {{ !$showInactive ? 'btn-dark' : 'btn-gray' }}">Active Products</a>
    <a href="{{ route('products.index', ['inactive' => 1]) }}" class="btn {{ $showInactive ? 'btn-dark' : 'btn-gray' }}">Disabled ({{ $inactiveCount }})</a>
</div>

<form method="GET" data-ajax-filter="products" class="mb-4 flex gap-2">
    @if($showInactive)<input type="hidden" name="inactive" value="1">@endif
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name/SKU/barcode..."
           class="border rounded px-3 py-2 w-64">
    <select name="category" class="border rounded px-3 py-2">
        <option value="">All Categories</option>
        @foreach($categories as $c)
            <option value="{{ $c }}" @selected(request('category')===$c)>{{ $c }}</option>
        @endforeach
    </select>
    <button class="btn btn-gray">Filter</button>
</form>

<div data-ajax-list="products">
@if(!$showInactive)
@if(auth()->user()->role === 'admin')
<div class="flex items-center gap-2 mb-3">
    <span data-bulk-count="products" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-red" data-bulk-submit="products"
            data-action-url="{{ route('products.destroy-selected') }}"
            data-confirm-message="Disable the selected products? They'll move to the Disabled list and can be reactivated anytime.">
        Disable Selected
    </button>
    <button type="button" class="btn btn-red" data-bulk-all-submit
            data-action-url="{{ route('products.destroy-all') }}"
            data-confirm-message="Disable ALL products on this list? They'll move to the Disabled list and can be reactivated anytime.">
        Disable All
    </button>
</div>
@endif
@else
<div class="flex items-center gap-2 mb-3 flex-wrap">
    <span data-bulk-count="products" class="text-sm text-gray-500"></span>
    <button type="button" class="btn btn-solid-green" data-bulk-submit="products"
            data-action-url="{{ route('products.reactivate-selected') }}"
            data-confirm-message="Reactivate the selected products?">
        Restore Selected
    </button>
    <button type="button" class="btn btn-solid-green" data-bulk-all-submit
            data-action-url="{{ route('products.reactivate-all') }}"
            data-confirm-message="Reactivate ALL disabled products on this list?">
        Restore All
    </button>
    @if(auth()->user()->role === 'admin')
    <button type="button" class="btn btn-red" data-bulk-submit="products" data-http-method="DELETE"
            data-action-url="{{ route('products.force-delete-selected') }}"
            data-confirm-message="Permanently delete the selected products? This cannot be undone. Products with sales/purchase history can't be removed this way.">
        Permanently Delete Selected
    </button>
    <button type="button" class="btn btn-red" data-bulk-all-submit data-http-method="DELETE"
            data-action-url="{{ route('products.force-delete-all') }}"
            data-confirm-message="Permanently delete ALL disabled products on this list? This cannot be undone. Products with sales/purchase history can't be removed this way.">
        Permanently Delete All
    </button>
    @endif
</div>
@endif
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left">
        <tr>
            <th class="p-3"><input type="checkbox" data-bulk-select-all="products"></th>
            <th class="p-3">Name</th><th class="p-3">SKU / Barcode</th><th class="p-3">Category</th>
            <th class="p-3">Stock</th><th class="p-3">Sale Price</th><th class="p-3">Actions</th>
        </tr>
    </thead>
    <tbody>
    @foreach($products as $p)
        <tr class="border-t {{ $p->isLowStock() ? 'bg-yellow-50' : '' }} {{ $showInactive ? 'opacity-60' : '' }}">
            <td class="p-3"><input type="checkbox" data-bulk-item="products" value="{{ $p->id }}"></td>
            <td class="p-3 font-medium">{{ $p->name }}</td>
            <td class="p-3 text-gray-500">{{ $p->sku }}<br><span class="text-xs">{{ $p->barcode }}</span></td>
            <td class="p-3">{{ $p->category }}</td>
            <td class="p-3">{{ $p->stock }} {{ $p->unit }}</td>
            <td class="p-3">{{ number_format($p->sale_price, 2) }}</td>
            <td class="p-3 space-x-1 whitespace-nowrap">
                @if($showInactive)
                    <form method="POST" action="{{ route('products.reactivate', $p) }}" class="inline">
                        @csrf
                        <button class="btn btn-solid-green">Reactivate</button>
                    </form>
                    @if(auth()->user()->role === 'admin')
                    <form method="POST" action="{{ route('products.force-delete-one', $p) }}" class="inline confirm-submit" data-confirm-message="Permanently delete this product? This cannot be undone, and only works if it has no sales/purchase history.">
                        @csrf @method('DELETE')
                        <button class="btn btn-red">Delete Forever</button>
                    </form>
                    @endif
                @else
                    <a href="{{ route('products.edit', $p) }}" class="btn btn-blue">Edit</a>
                    <a href="{{ route('products.price-history', $p) }}" class="btn btn-gray">History</a>
                    @if(auth()->user()->role === 'admin')
                    <form method="POST" action="{{ route('products.destroy', $p) }}" class="inline confirm-submit" data-confirm-message="Disable this product? It will be hidden from Products and Billing, but past sales stay intact — you can reactivate it anytime.">
                        @csrf @method('DELETE')
                        <button class="btn btn-red">Disable</button>
                    </form>
                    @endif
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
<div class="mt-4">{{ $products->links() }}</div>
</div>
@endsection
