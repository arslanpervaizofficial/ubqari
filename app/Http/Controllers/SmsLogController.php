<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Http\Request;

class SmsLogController extends Controller
{
    /** SMS log table (paginated) + the manual send form. The API key is
     *  never shown here — it lives only in the admin settings form, as a
     *  blank password field (see settings-fragment.txt). */
    public function index()
    {
        $logs = SmsLog::with(['customer', 'user'])->latest()->paginate(20);
        $customers = Customer::orderBy('name')->get();
        $smsTestMode = SmsService::isTestMode(AppSetting::current());

        return view('sms_logs.index', compact('logs', 'customers', 'smsTestMode'));
    }

    /** MANUAL ONLY: one SMS, only when a user submits this form. Pick a
     *  customer or type a phone number, type the message, send.
     *  SmsService::send() writes the SmsLog row — this method writes no
     *  second row.
     *
     *  v3.1.0 (M5): if a customer is picked, THEIR stored phone is used
     *  (a typed number can no longer redirect a customer's reminder to
     *  an arbitrary number). A free-text phone with no customer is
     *  admin-only. A per-user daily cap (50/day) applies on top of the
     *  route throttle (throttle:10,1) — sms_logs has a user_id column,
     *  so the cap is enforceable and is NOT deferred. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s]{7,15}$/'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        // Per-user daily cap: 50 logged sends per user per day.
        $sentToday = SmsLog::where('user_id', auth()->id())
            ->whereDate('created_at', today())
            ->count();
        if ($sentToday >= 50) {
            return back()->withInput()->withErrors([
                'message' => 'Daily SMS limit reached (50 per user). Please try again tomorrow.',
            ]);
        }

        $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;

        if ($customer) {
            // Stored phone wins when a customer is picked.
            $phone = $customer->phone;
        } else {
            // Free-text phone with no customer: admin role only.
            if (!$request->user() || !$request->user()->isAdmin()) {
                return back()->withInput()->withErrors([
                    'phone' => 'Pick a customer to send to. Typing a phone number directly is admin-only.',
                ]);
            }
            $phone = $data['phone'] ?? null;
        }

        if (empty($phone)) {
            return back()->withInput()->withErrors([
                'phone' => 'Pick a customer who has a phone number, or type a phone number.',
            ]);
        }

        $result = (new SmsService)->send($phone, $data['message'], $customer?->id, auth()->id());

        if ($result['status'] === 'test') {
            return back()->with('status', 'SMS logged in test mode (no real SMS sent).');
        }
        if ($result['ok']) {
            return back()->with('status', 'SMS sent.');
        }
        return back()->with('status', 'SMS failed to send — see the log below for the provider response.');
    }
}
