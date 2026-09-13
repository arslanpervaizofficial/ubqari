@extends('layouts.app')
@section('title', 'Login - ' . \App\Models\AppSetting::current()->menu_title)
@section('content')
<div class="relative w-full max-w-sm mx-auto">
    <div class="bg-white rounded-2xl shadow-xl p-8">
        <div class="border-t border-gray-200 mb-6"></div>
        <h1 class="text-2xl text-center tracking-widest text-slate-800 font-light mb-8">USER LOGIN</h1>

        @if(session('status'))
            <p class="bg-blue-50 text-blue-700 text-sm rounded-lg px-4 py-3 mb-5">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('login') }}" novalidate class="space-y-5">
            @csrf
            <div class="flex items-center gap-3 border-b border-slate-700/40 pb-2">
                <svg class="w-5 h-5 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus data-label="Email"
                       placeholder="Email ID" class="bg-transparent flex-1 outline-none text-slate-800 placeholder-slate-600">
            </div>
            @error('email') <p class="text-red-700 text-xs -mt-3">{{ $message }}</p> @enderror

            <div class="flex items-center gap-3 border-b border-slate-700/40 pb-2">
                <svg class="w-5 h-5 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 10-8 0v4h8z"/></svg>
                <input type="password" id="login-password-input" name="password" required data-label="Password"
                       placeholder="Password" class="bg-transparent flex-1 outline-none text-slate-800 placeholder-slate-600">
                <button type="button" id="toggle-login-password" tabindex="-1" aria-label="Show password" class="text-slate-500 hover:text-slate-800 shrink-0">
                    <svg id="login-password-eye-open" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <svg id="login-password-eye-closed" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
            </div>

            <div class="flex items-center justify-between text-sm text-slate-700">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="remember" checked> Remember me
                </label>
            </div>

            <button class="w-full bg-slate-900 text-white tracking-widest py-3 rounded-lg hover:bg-slate-800 transition">LOGIN</button>
        </form>
        <div class="border-b border-gray-200 mt-6"></div>
    </div>
</div>
<script>
document.getElementById('toggle-login-password').addEventListener('click', function () {
    const input = document.getElementById('login-password-input');
    const eyeOpen = document.getElementById('login-password-eye-open');
    const eyeClosed = document.getElementById('login-password-eye-closed');
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    eyeOpen.classList.toggle('hidden', !showing);
    eyeClosed.classList.toggle('hidden', showing);
    this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
});
</script>
@endsection
