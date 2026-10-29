<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function edit()
    {
        $settings = AppSetting::current();
        return view('settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'menu_title' => ['required', 'string', 'max:100'],
            'print_title' => ['required', 'string', 'max:100'],
            'print_address' => ['nullable', 'string', 'max:150'],
            'print_phone' => ['nullable', 'string', 'max:50'],
            'sms_provider' => ['nullable', 'string', 'max:100'],
            'sms_api_url' => ['nullable', 'string', 'max:255'],
            'sms_api_key' => ['nullable', 'string', 'max:255'],
            'sms_sender' => ['nullable', 'string', 'max:50'],
            'sms_test_mode' => ['nullable', 'boolean'],
            'jazzcash_merchant_id' => ['nullable', 'string', 'max:100'],
            'jazzcash_password' => ['nullable', 'string', 'max:100'],
            'jazzcash_integrity_salt' => ['nullable', 'string', 'max:255'],
            'jazzcash_live_mode' => ['nullable', 'boolean'],
            'easypaisa_store_id' => ['nullable', 'string', 'max:100'],
            'easypaisa_hash_key' => ['nullable', 'string', 'max:255'],
            'easypaisa_live_mode' => ['nullable', 'boolean'],
        ]);

        // v3.0: secret fields are shown as blank password inputs — a blank
        // value means "keep the saved secret", never wipe it.
        foreach (['sms_api_key', 'jazzcash_password', 'jazzcash_integrity_salt', 'easypaisa_hash_key'] as $secret) {
            if (!$request->filled($secret)) {
                unset($data[$secret]);
            }
        }
        // An unchecked checkbox sends nothing, so set booleans explicitly
        // or test/live mode could never be switched OFF again.
        $data['sms_test_mode'] = $request->boolean('sms_test_mode');
        $data['jazzcash_live_mode'] = $request->boolean('jazzcash_live_mode');
        $data['easypaisa_live_mode'] = $request->boolean('easypaisa_live_mode');

        AppSetting::current()->update($data);
        AppSetting::forget();

        return back()->with('status', 'Project settings saved.');
    }
}
