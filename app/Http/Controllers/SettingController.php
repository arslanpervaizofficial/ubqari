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
        ]);

        AppSetting::current()->update($data);
        AppSetting::forget();

        return back()->with('status', 'Project settings saved.');
    }
}
