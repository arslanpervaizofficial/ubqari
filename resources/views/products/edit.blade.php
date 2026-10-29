@extends('layouts.app')
@section('title', __('Edit Product'))
@section('content')
<h1 class="text-2xl font-bold mb-4">{{ __('Edit Product') }}</h1>
<form method="POST" action="{{ route('products.update', $product) }}" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf @method('PUT')
    @include('products._form')
    <div class="mt-6">
        <button class="btn btn-dark">{{ __('Update Product') }}</button>
    </div>
</form>

{{-- v3.0 FEATURE 1 — batches section (display-only, outside the form). --}}
@include('products.batches')
@endsection
