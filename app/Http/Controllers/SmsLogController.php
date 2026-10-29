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
     *  customer or type a phone number (typed phone wins if both are
     *  given), type the message, send. SmsService::send() writes the
     *  SmsLog row — this method writes no second row. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s]{7,15}$/'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;

        // Typed phone wins; otherwise fall back to the chosen customer's
        // number. A customer_id that passed validation but resolves to
        // nothing here (e.g. soft-deleted) has no usable number either.
        $phone = $request->filled('phone') ? $data['phone'] : ($customer?->phone);

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
