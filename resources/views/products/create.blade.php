@extends('layouts.app')
@section('title', 'Add Product')
@section('content')
<h1 class="text-2xl font-bold mb-4">Add Product</h1>

@if(session('reactivate_candidate'))
    @php $rc = session('reactivate_candidate'); @endphp
    <div class="mb-4 bg-amber-50 border border-amber-300 text-amber-800 text-sm px-4 py-3 rounded-lg flex items-center justify-between">
        <span>This SKU belongs to a disabled product ("{{ $rc->name }}"). Reactivate it instead of creating a duplicate?</span>
        <form method="POST" action="{{ route('products.reactivate', $rc) }}">
            @csrf
            <button class="btn btn-solid-yellow">Reactivate "{{ $rc->name }}"</button>
        </form>
    </div>
@endif

<form method="POST" action="{{ route('products.store') }}" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf
    @include('products._form')
    <div class="mt-6">
        <button class="btn btn-dark">Save Product</button>
    </div>
</form>
@endsection
