@extends('layouts.app')
@section('title', 'Access Restricted')
@section('content')
<div class="max-w-lg mx-auto mt-10 md:mt-20 bg-white rounded-xl shadow-sm p-8 text-center">
    <div class="mx-auto w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mb-4">
        <svg class="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 10-8 0v2"/>
        </svg>
    </div>
    <h1 class="text-xl font-bold mb-2">Access Restricted</h1>
    <p class="text-gray-500 mb-6">
        @php($msg = $exception->getMessage())
        {{ $msg && $msg !== 'This action is unauthorized.' ? $msg : "You don't have permission to do this — it's limited to certain roles (like Admin or Manager). If you think you should have access, ask an Admin." }}
    </p>
    <a href="{{ auth()->user() && auth()->user()->role === 'cashier' ? route('pos.index') : route('dashboard') }}" class="btn btn-dark">
        Back to {{ auth()->user() && auth()->user()->role === 'cashier' ? 'Billing' : 'Dashboard' }}
    </a>
</div>
@endsection
