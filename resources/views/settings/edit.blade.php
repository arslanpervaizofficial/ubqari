@extends('layouts.app')
@section('title', __('Project Settings'))
@section('content')
<h1 class="text-2xl font-bold mb-4">{{ __('Project Settings') }}</h1>

<form method="POST" action="{{ route('settings.update') }}" class="bg-white p-6 rounded-xl shadow-sm w-full max-w-xl">
    @csrf
    @method('PUT')

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Project Title (menu / sidebar / browser tab)') }}</label>
        <input name="menu_title" value="{{ old('menu_title', $settings->menu_title) }}" required maxlength="100"
               data-label="Project Title" class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('Shown at the top of the sidebar and in the browser tab title.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Print Title (invoice header)') }}</label>
        <input name="print_title" value="{{ old('print_title', $settings->print_title) }}" required maxlength="100"
               data-label="Print Title" class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('Shown at the top of the printed invoice/receipt — can be different from the menu title above.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Receipt Address (optional)') }}</label>
        <input name="print_address" value="{{ old('print_address', $settings->print_address) }}" maxlength="150"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('Printed under the shop name on the thermal receipt.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Receipt Phone (optional)') }}</label>
        <input name="print_phone" value="{{ old('print_phone', $settings->print_phone) }}" maxlength="50"
               class="w-full border rounded px-3 py-2">
    </div>


    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('SMS Provider (gateway name)') }}</label>
        <input name="sms_provider" value="{{ old('sms_provider', $settings->sms_provider) }}" maxlength="100"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('Just a label for your own reference (e.g. the gateway company name). The app sends to any gateway via a generic HTTP form — it is not tied to one vendor.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('SMS API URL (gateway endpoint)') }}</label>
        <input name="sms_api_url" value="{{ old('sms_api_url', $settings->sms_api_url) }}" maxlength="255"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('The full URL the app POSTs to. Leave blank to stay in test mode.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('SMS API Key (kept hidden — leave blank to keep the saved key)') }}</label>
        <input type="password" name="sms_api_key" value="" maxlength="255" autocomplete="off"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('The saved key is never displayed, not even masked. Leave this blank to keep the current key; type a new key only to replace it.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('SMS Sender (sender ID / sender name)') }}</label>
        <input name="sms_sender" value="{{ old('sms_sender', $settings->sms_sender) }}" maxlength="50"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('The sender name/ID shown on the customer\'s phone, if your gateway supports it.') }}</p>
    </div>

    <div class="mb-4">
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" name="sms_test_mode" value="1" {{ old('sms_test_mode', $settings->sms_test_mode) ? 'checked' : '' }}>
            {{ __('SMS Test Mode (when ON, messages are only logged, never sent to a gateway)') }}
        </label>
        <p class="text-xs text-gray-400 mt-1">{{ __('Keep this ON until the gateway is tested. Test-mode messages appear in the SMS log with status "test".') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('JazzCash Merchant ID (optional)') }}</label>
        <input name="jazzcash_merchant_id" value="{{ old('jazzcash_merchant_id', $settings->jazzcash_merchant_id) }}" maxlength="100"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('From your JazzCash merchant account. Leave blank until you have sandbox or live credentials.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('JazzCash Password (leave blank to keep the saved password)') }}</label>
        <input type="password" name="jazzcash_password" value="" maxlength="100" autocomplete="off"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('JazzCash merchant password. Leave blank to keep the current one.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('JazzCash Integrity Salt (leave blank to keep the saved salt)') }}</label>
        <input type="password" name="jazzcash_integrity_salt" value="" maxlength="255" autocomplete="off"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('Used to sign requests and verify callbacks. Never share it; if it leaks, regenerate it in JazzCash and update it here.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1 flex items-center gap-2">
            <input type="checkbox" name="jazzcash_live_mode" value="1" {{ old('jazzcash_live_mode', $settings->jazzcash_live_mode) ? 'checked' : '' }}>
            {{ __('JazzCash Live Mode') }}
        </label>
        <p class="text-xs text-gray-400 mt-1">{{ __('OFF = JazzCash sandbox (default, test mode). Switch ON only with real live merchant credentials.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Easypaisa Store ID (optional)') }}</label>
        <input name="easypaisa_store_id" value="{{ old('easypaisa_store_id', $settings->easypaisa_store_id) }}" maxlength="100"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('From your Easypaisa merchant account. Leave blank until you have sandbox or live credentials.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1">{{ __('Easypaisa Hash Key (leave blank to keep the saved key)') }}</label>
        <input type="password" name="easypaisa_hash_key" value="" maxlength="255" autocomplete="off"
               class="w-full border rounded px-3 py-2">
        <p class="text-xs text-gray-400 mt-1">{{ __('Used to sign requests and verify callbacks. Never share it; if it leaks, regenerate it in Easypaisa and update it here.') }}</p>
    </div>

    <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1 flex items-center gap-2">
            <input type="checkbox" name="easypaisa_live_mode" value="1" {{ old('easypaisa_live_mode', $settings->easypaisa_live_mode) ? 'checked' : '' }}>
            {{ __('Easypaisa Live Mode') }}
        </label>
        <p class="text-xs text-gray-400 mt-1">{{ __('OFF = Easypaisa sandbox (default, test mode). Switch ON only with real live merchant credentials.') }}</p>
    </div>

    <button class="btn btn-dark">{{ __('Save Settings') }}</button>
</form>
@endsection
