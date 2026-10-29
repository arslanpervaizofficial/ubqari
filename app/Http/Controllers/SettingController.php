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

        // v3.1.0 (L4): secret fields are no longer in
        // AppSetting::$fillable, so they are assigned explicitly while
        // the plain settings still go through update(). The blank-means-
        // keep behaviour above is unchanged: secrets absent from $data
        // are never touched here.
        $secrets = array_intersect_key($data, array_flip(['sms_api_key', 'jazzcash_password', 'jazzcash_integrity_salt', 'easypaisa_hash_key']));
        $plain = array_diff_key($data, $secrets);
        $settings = AppSetting::current();
        $settings->update($plain);
        if ($secrets) {
            $settings->forceFill($secrets)->save();
        }
        AppSetting::forget();

        return back()->with('status', 'Project settings saved.');
    }
}
