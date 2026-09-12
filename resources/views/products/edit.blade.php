@extends('layouts.app')
@section('title', 'Edit Product')
@section('content')
<h1 class="text-2xl font-bold mb-4">Edit Product</h1>
<form method="POST" action="{{ route('products.update', $product) }}" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf @method('PUT')
    @include('products._form')
    <div class="mt-6">
        <button class="btn btn-dark">Update Product</button>
    </div>
</form>
@endsection
