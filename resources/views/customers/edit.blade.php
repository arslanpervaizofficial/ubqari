@extends('layouts.app')
@section('title', 'Edit Customer')
@section('content')
<h1 class="text-2xl font-bold mb-4">Edit Customer</h1>
<form method="POST" action="{{ route('customers.update', $customer) }}" enctype="multipart/form-data" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf @method('PUT')
    @include('customers._form')
    <div class="mt-6">
        <button class="btn btn-dark">Update Customer</button>
    </div>
</form>
@endsection
