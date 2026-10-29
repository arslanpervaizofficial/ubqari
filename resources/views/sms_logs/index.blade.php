@extends('layouts.app')
@section('title', __('SMS Log'))
@section('content')
<h1 class="text-2xl font-bold mb-4">{{ __('SMS Log') }}</h1>

@if($smsTestMode)
    <p class="mb-4 bg-yellow-100 text-yellow-800 px-4 py-2 rounded-lg">{{ __('Test mode is ON — messages are only logged here, never sent to a real gateway. Turn test mode off in Project Settings (and fill in the gateway URL and key) to send real SMS.') }}</p>
@endif

<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h2 class="font-semibold mb-3">{{ __('Send SMS') }}</h2>
    <p class="text-sm text-gray-500 mb-3">{{ __('Manual only — this form sends one SMS when you submit it. Nothing in the app sends SMS automatically.') }}</p>
    <form method="POST" action="{{ route('sms-logs.store') }}">
        @csrf
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Customer (optional)') }}</label>
                <select name="customer_id" class="w-full border rounded px-3 py-2">
                    <option value="">{{ __('— No customer, type a number instead —') }}</option>
                    @foreach($customers as $c)
                        <option value="{{ $c->id }}" {{ old('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }} ({{ $c->phone }})</option>
                    @endforeach
                </select>
                @error('customer_id') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('Phone number (optional if a customer is picked)') }}</label>
                <input name="phone" value="{{ old('phone') }}" placeholder="{{ __('e.g. 03001234567') }}" class="w-full border rounded px-3 py-2">
                @error('phone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="mb-3">
            <label class="block text-sm text-gray-600 mb-1">{{ __('Message') }}</label>
            <textarea name="message" rows="3" maxlength="1000" required class="w-full border rounded px-3 py-2">{{ old('message') }}</textarea>
            @error('message') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <button class="btn btn-solid-blue">{{ __('Send SMS') }}</button>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b">
                <th class="px-4 py-3">{{ __('Date') }}</th>
                <th class="px-4 py-3">{{ __('Customer') }}</th>
                <th class="px-4 py-3">{{ __('Phone') }}</th>
                <th class="px-4 py-3">{{ __('Message') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Sent by') }}</th>
                <th class="px-4 py-3">{{ __('Provider response') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr class="border-b">
                    <td class="px-4 py-3 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3">{{ $log->customer->name ?? '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $log->phone }}</td>
                    <td class="px-4 py-3 break-words">{{ $log->message }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($log->status === 'sent')
                            <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs font-semibold">{{ __('sent') }}</span>
                        @elseif($log->status === 'failed')
                            <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs font-semibold">{{ __('failed') }}</span>
                        @else
                            <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs font-semibold">{{ __('test') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $log->user->name ?? '—' }}</td>
                    <td class="px-4 py-3 break-words text-gray-500">{{ \Illuminate\Support\Str::limit($log->provider_response, 200) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-6 text-center text-gray-400">{{ __('No SMS logged yet.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $logs->links() }}</div>
@endsection
