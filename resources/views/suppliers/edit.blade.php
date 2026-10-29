@extends('layouts.app')
@section('title', __('Edit Supplier'))
@section('content')
<h1 class="text-2xl font-bold mb-4">{{ __('Edit Supplier') }}</h1>
<form method="POST" action="{{ route('suppliers.update', $supplier) }}" enctype="multipart/form-data" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf @method('PUT')
    @include('suppliers._form')
    <div class="mt-6">
        <button class="btn btn-dark">{{ __('Update Supplier') }}</button>
    </div>
</form>
@endsection
