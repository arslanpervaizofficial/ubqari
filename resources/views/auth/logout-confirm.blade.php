@extends('layouts.app')
@section('title', __('Log Out'))
@section('content')
<div class="relative w-full max-w-sm mx-auto">
    <div class="bg-white rounded-2xl shadow-xl p-8">
        <h1 class="text-2xl text-center tracking-widest text-slate-800 font-light mb-4">{{ __('Log Out') }}</h1>
        <p class="text-sm text-center text-slate-500 mb-6">{{ __('Are you sure you want to log out?') }}</p>
        <form method="POST" action="{{ route('logout') }}" class="space-y-3">
            @csrf
            <button type="submit" class="btn btn-red w-full">{{ __('Log Out') }}</button>
            <a href="{{ url()->previous() }}" class="btn btn-gray w-full text-center block">{{ __('Cancel') }}</a>
        </form>
    </div>
</div>
@endsection
