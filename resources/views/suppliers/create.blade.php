@extends('layouts.app')
@section('title', 'Add Supplier')
@section('content')
<h1 class="text-2xl font-bold mb-4">Add Supplier</h1>
<form method="POST" action="{{ route('suppliers.store') }}" enctype="multipart/form-data" novalidate class="bg-white p-6 rounded-xl shadow-sm w-full">
    @csrf
    @include('suppliers._form')
    <div class="mt-6">
        <button class="btn btn-dark">Save Supplier</button>
    </div>
</form>
@endsection
