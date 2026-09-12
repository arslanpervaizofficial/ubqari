@extends('layouts.app')
@section('title', 'Add Customer')
@section('content')
<h1 class="text-2xl font-bold mb-4">Add Customer</h1>
<form method="POST" action="{{ route('customers.store') }}" enctype="multipart/form-data" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf
    @include('customers._form')
    <div class="mt-6">
        <button class="btn btn-dark">Save Customer</button>
    </div>
</form>
@endsection
