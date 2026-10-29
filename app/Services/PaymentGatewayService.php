<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\OnlinePayment;
use App\Models\Order;
use InvalidArgumentException;

/** FEATURE 9 — JazzCash + Easypaisa hosted checkout (Pakistan).
 *
 * SANDBOX / TEST MODE IS THE DEFAULT. Live mode is used ONLY when the
 * matching *_live_mode setting is explicitly switched on in Settings
 * (see settings-fragment.txt). No real merchant credential is hardcoded
 * anywhere here: every id, password, salt and key is read from
 * AppSetting::current(), and the only built-in defaults are empty
 * strings (i.e. "not configured yet" — the gateway will reject the
 * form, no money moves, and the row stays pending).
 *
 * FLOW
 *   initiate()  — creates a PENDING OnlinePayment row and returns the
 *                 gateway's hosted-checkout form action + fields. The
 *                 caller renders them as an auto-submitting form
 *                 (orders.online-payment-redirect view); the customer
 *                 pays on the gateway's own page, never on this shop's
 *                 server, so no card/wallet secret ever touches it.
 *   verifyCallback() — recomputes the gateway's response hash from the
 *                 posted payload and returns true ONLY when the hash
 *                 matches AND the gateway reports success. On ANY
 *                 failure it returns false and the caller must not
 *                 record any payment (OnlinePaymentController obeys
 *                 this: it marks the row failed and records nothing).
 *
 * HASH SCHEMES (exact field lists — also documented in NOTES.txt):
 *
 *   JazzCash (sorted-field SHA256 with integrity salt):
 *     hash = UPPERCASE( SHA256( salt + '&' + value1 + '&' + value2 ... ) )
 *     where the values are ALL fields being sent / returned, EXCEPT
 *     pp_SecureHash itself, sorted by field name A-Z, with empty
 *     values skipped. The same rule is used for the request hash and
 *     for verifying a response hash, so a request hash covers every
 *     posted field (including pp_Password) while a response hash
 *     covers every returned field.
 *
 *   Easypaisa (concatenated SHA256 with hash key):
 *     request hash (merchantHashedReq) =
 *       UPPERCASE( SHA256( storeId . amount . orderRefNum . expiryDate
 *                          . hashKey ) )
 *       with amount as the exact 2-decimal string sent, expiryDate in
 *       "Ymd His" format, and a missing field counting as an empty
 *       string (fields are NOT skipped — position matters).
 *     response hash (merchantHashedResp) =
 *       UPPERCASE( SHA256( storeId . orderRefNum . status
 *                          . transactionId . hashKey ) )
 *       with storeId taken from Settings (never trusted from the
 *       payload) and a missing field counting as an empty string.
 *     Easypaisa success statuses accepted: '0000', 'Success', 'paid'
 *     (compared case-insensitively, after trimming).
 *
 *   CAVEAT (read NOTES.txt before going live): these are the standard
 *   documented schemes for each gateway, implemented from their public
 *   integration documentation — they have NOT been live-tested, because
 *   live testing needs real merchant credentials + an approved callback
 *   URL from JazzCash/Easypaisa, which this shop does not have yet.
 *   The Easypaisa response field names in particular (orderRefNum /
 *   status / transactionId / merchantHashedResp) must be re-checked
 *   against the merchant's current Easypaisa guide during sandbox
 *   testing; both hash computations live in one small method each
 *   (jazzcashHash / easypaisaResponseHash) so a correction is a
 *   one-line change.
 *
 * REFERENCE NUMBERS: both gateways get the same transaction reference
 *   'UBQARI-{online_payment_id}' (pp_TxnRefNo for JazzCash,
 *   orderRefNum for Easypaisa). Callbacks are matched back to the
 *   pending row by parsing that reference — never by trusting an
 *   order id supplied in the payload.
 */
class PaymentGatewayService
{
    public const GATEWAYS = ['jazzcash', 'easypaisa'];

    // JazzCash hosted-checkout form endpoints.
    public const JAZZCASH_SANDBOX_URL = 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/';
    public const JAZZCASH_LIVE_URL = 'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/';

    // Easypaisa hosted-checkout form endpoints.
    public const EASYPAISA_SANDBOX_URL = 'https://easypaystg.easypaisa.com.pk/easypay/Index.jsf';
    public const EASYPAISA_LIVE_URL = 'https://easypay.easypaisa.com.pk/easypay/Index.jsf';

    /** Live ONLY when the gateway's *_live_mode setting is on.
     *  Anything else (off, missing, null) means sandbox. */
    public function isLive(string $gateway): bool
    {
        $this->assertGateway($gateway);
        $settings = AppSetting::current();

        return (bool) ($gateway === 'jazzcash'
            ? $settings->jazzcash_live_mode
            : $settings->easypaisa_live_mode);
    }

    public function gatewayActionUrl(string $gateway): string
    {
        $this->assertGateway($gateway);
        $live = $this->isLive($gateway);

        return $gateway === 'jazzcash'
            ? ($live ? self::JAZZCASH_LIVE_URL : self::JAZZCASH_SANDBOX_URL)
            : ($live ? self::EASYPAISA_LIVE_URL : self::EASYPAISA_SANDBOX_URL);
    }

    /** Creates the pending row and returns everything the redirect form
     *  needs: ['payment', 'gateway', 'action', 'fields', 'is_live'].
     *  Secrets are NEVER included in the stored request_payload. */
    public function initiate(Order $order, string $gateway, float $amount, ?int $userId = null): array
    {
        $this->assertGateway($gateway);
        $settings = AppSetting::current();

        $payment = OnlinePayment::create([
            'order_id' => $order->id,
            'gateway' => $gateway,
            'amount' => round($amount, 2),
            'status' => 'pending',
            'user_id' => $userId,
        ]);

        // Shared reference for both gateways; see class docblock.
        $txnRef = 'UBQARI-' . $payment->id;
        $callbackUrl = route('online-payments.callback.' . $gateway);

        if ($gateway === 'jazzcash') {
            // Shop time (Asia/Karachi), matching OrderController::changeDate.
            $now = now('Asia/Karachi');
            $fields = [
                'pp_Version' => '1.1',
                'pp_TxnType' => 'MWALLET',
                'pp_Language' => 'EN',
                'pp_MerchantID' => (string) ($settings->jazzcash_merchant_id ?? ''),
                'pp_SubMerchantID' => '',
                'pp_Password' => (string) ($settings->jazzcash_password ?? ''),
                'pp_BankID' => '',
                'pp_ProductID' => '',
                'pp_TxnRefNo' => $txnRef,
                // JazzCash amounts are in paisa (PKR x 100), integer.
                'pp_Amount' => (string) (int) round($payment->amount * 100),
                'pp_TxnCurrency' => 'PKR',
                'pp_TxnDateTime' => $now->format('YmdHis'),
                'pp_TxnExpiryDateTime' => $now->copy()->addDay()->format('YmdHis'),
                'pp_ReturnURL' => $callbackUrl,
                'ppmpf_1' => (string) $order->order_number,
            ];
            $fields['pp_SecureHash'] = $this->jazzcashHash(
                $fields, (string) ($settings->jazzcash_integrity_salt ?? '')
            );
            // Store the request WITHOUT the password (never persist it
            // in a payment row); the hash itself is safe to keep.
            $stored = $fields;
            unset($stored['pp_Password']);
        } else {
            $amountString = number_format($payment->amount, 2, '.', '');
            $expiryDate = now('Asia/Karachi')->addDay()->format('Ymd His');
            $storeId = (string) ($settings->easypaisa_store_id ?? '');
            $hashKey = (string) ($settings->easypaisa_hash_key ?? '');
            $fields = [
                'storeId' => $storeId,
                'amount' => $amountString,
                'postBackURL' => $callbackUrl,
                'orderRefNum' => $txnRef,
                'expiryDate' => $expiryDate,
                'paymentMethod' => '',
            ];
            $fields['merchantHashedReq'] = strtoupper(hash(
                'sha256',
                $storeId . $amountString . $txnRef . $expiryDate . $hashKey
            ));
            $stored = $fields;
        }

        $payment->update(['request_payload' => $stored]);

        return [
            'payment' => $payment,
            'gateway' => $gateway,
            'action' => $this->gatewayActionUrl($gateway),
            'fields' => $fields,
            'is_live' => $this->isLive($gateway),
        ];
    }

    /** True ONLY when the gateway's response hash recomputes correctly
     *  AND the gateway reports success. Any missing field, any hash
     *  mismatch, any non-success status => false. Never throws for a
     *  malformed payload — a bad callback is simply unverified. */
    public function verifyCallback(string $gateway, array $payload): bool
    {
        if (!in_array($gateway, self::GATEWAYS, true)) {
            return false;
        }
        $settings = AppSetting::current();

        if ($gateway === 'jazzcash') {
            // JazzCash success code; anything else (including a
            // customer-cancelled payment) is not a payment.
            if (($payload['pp_ResponseCode'] ?? null) !== '000') {
                return false;
            }
            $given = $payload['pp_SecureHash'] ?? null;
            if (!is_string($given) || $given === '') {
                return false;
            }
            $expected = $this->jazzcashHash(
                $payload, (string) ($settings->jazzcash_integrity_salt ?? '')
            );

            return hash_equals($expected, strtoupper($given));
        }

        // Easypaisa.
        $status = trim((string) ($payload['status'] ?? ''));
        if (!in_array(strtolower($status), ['0000', 'success', 'paid'], true)) {
            return false;
        }
        $given = $payload['merchantHashedResp'] ?? null;
        if (!is_string($given) || $given === '') {
            return false;
        }
        $expected = $this->easypaisaResponseHash($payload, $settings);

        return hash_equals($expected, strtoupper($given));
    }

    /** Finds the payment a callback belongs to, from the gateway's own
     *  reference field — pp_TxnRefNo (JazzCash) / orderRefNum
     *  (Easypaisa) — in 'UBQARI-{id}' form. Returns null when the
     *  reference is missing, malformed, or matches no row. The caller
     *  still has to verify the hash before trusting the row. */
    public function paymentFromCallbackRef(string $gateway, array $payload): ?OnlinePayment
    {
        $ref = $gateway === 'jazzcash'
            ? ($payload['pp_TxnRefNo'] ?? null)
            : ($payload['orderRefNum'] ?? null);

        if (!is_string($ref) || !preg_match('/^UBQARI-(\d+)$/', $ref, $m)) {
            return null;
        }

        return OnlinePayment::find((int) $m[1]);
    }

    /** Amount the gateway says was paid, in PKR. JazzCash reports
     *  paisa (divide by 100); Easypaisa reports PKR directly. Returns
     *  null when the payload carries no usable amount — the caller
     *  then treats the callback as unverified. */
    public function callbackAmount(string $gateway, array $payload): ?float
    {
        if ($gateway === 'jazzcash') {
            if (!isset($payload['pp_Amount']) || !is_numeric($payload['pp_Amount'])) {
                return null;
            }

            return round(((float) $payload['pp_Amount']) / 100, 2);
        }

        if (!isset($payload['amount']) || !is_numeric($payload['amount'])) {
            return null;
        }

        return round((float) $payload['amount'], 2);
    }

    /** Gateway's own transaction id for storage (NOT our reference).
     *  Falls back to our reference when the gateway sends no separate
     *  id, so gateway_txn_id is never left blank on a paid row. */
    public function callbackTransactionId(string $gateway, array $payload): ?string
    {
        if ($gateway === 'jazzcash') {
            $id = $payload['pp_TransactionID'] ?? $payload['pp_TxnRefNo'] ?? null;
        } else {
            $id = $payload['transactionId'] ?? $payload['orderRefNum'] ?? null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    // -----------------------------------------------------------------
    // Hash helpers (one per gateway, so a merchant-doc correction is a
    // one-line change). See the class docblock for the exact schemes.
    // -----------------------------------------------------------------

    private function jazzcashHash(array $fields, string $salt): string
    {
        $data = $fields;
        unset($data['pp_SecureHash']);
        ksort($data);
        $values = [];
        foreach ($data as $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $values[] = (string) $value;
        }

        return strtoupper(hash('sha256', $salt . '&' . implode('&', $values)));
    }

    private function easypaisaResponseHash(array $payload, AppSetting $settings): string
    {
        $storeId = (string) ($settings->easypaisa_store_id ?? '');
        $hashKey = (string) ($settings->easypaisa_hash_key ?? '');
        $orderRefNum = (string) ($payload['orderRefNum'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $transactionId = (string) ($payload['transactionId'] ?? '');

        return strtoupper(hash(
            'sha256',
            $storeId . $orderRefNum . $status . $transactionId . $hashKey
        ));
    }

    private function assertGateway(string $gateway): void
    {
        if (!in_array($gateway, self::GATEWAYS, true)) {
            throw new InvalidArgumentException("Unknown payment gateway: {$gateway}");
        }
    }
}
