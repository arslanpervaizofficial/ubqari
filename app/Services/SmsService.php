<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Feature 5 — GENERIC HTTP SMS gateway (not tied to any one vendor).
 *
 * Settings are read from AppSetting::current() under the exact field
 * names in settings-fragment.txt: sms_provider, sms_api_url, sms_api_key,
 * sms_sender, sms_test_mode. No vendor SDK, no vendor-specific payload:
 * send() POSTs one generic form (api_key + sender + phone + message) to
 * whatever URL the shop configured. If a future gateway needs different
 * field names, that mapping belongs here, in this one place — not in the
 * controllers.
 *
 * TEST MODE IS THE DEFAULT: whenever sms_test_mode is on, OR sms_api_url
 * or sms_api_key is empty (e.g. a fresh install, or settings not merged
 * yet), NO real gateway is ever called. The attempt is written to
 * sms_logs with status 'test' and send() returns success.
 *
 * NEVER THROWS ON GATEWAY FAILURE: a failed HTTP call, a non-2xx
 * response, or a transport exception all become a 'failed' SmsLog row
 * plus ['ok' => false, ...] for the caller to flash. Callers must NOT
 * write a second SmsLog row — send() already wrote the one row for
 * this attempt (sent / failed / test).
 *
 * v3.1.0 (M16) — SENT SEMANTICS, READ THIS BEFORE TRUSTING 'sent':
 * a 2xx HTTP status alone no longer means 'sent'. If the gateway body
 * parses as JSON carrying an error indicator (status error/failed, or
 * success=false, or a non-empty error/errors field), the attempt is
 * marked 'failed' even on a 2xx. This check is deliberately
 * CONSERVATIVE and gateway-agnostic: it only ever flips sent -> failed
 * on an explicit error signal, never on an unrecognised success shape,
 * so an unknown gateway's success format still counts as sent.
 * Gateway-specific parsing (per-vendor success codes/messages) remains
 * a follow-up — this heuristic cannot know every vendor's format.
 * The raw body is already stored in sms_logs.provider_response (the
 * SMS Log page shows it truncated to 200 chars), so no new column was
 * needed for this fix.
 *
 * MANUAL ONLY: this service is called only from SmsLogController::store()
 * and CustomerController::sendReminder(), both behind a user clicking a
 * button. No sale, order-completion, or other automatic hook calls it. */
class SmsService
{
    /** True when no real gateway may be called for these settings. */
    public static function isTestMode(?AppSetting $settings = null): bool
    {
        $settings ??= AppSetting::current();

        if (filter_var($settings->sms_test_mode ?? null, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return empty($settings->sms_api_url) || empty($settings->sms_api_key);
    }

    /**
     * @return array{ok: bool, status: string, response: string, log: SmsLog}
     *         status is one of 'sent', 'failed', 'test'.
     */
    public function send(string $phone, string $message, ?int $customerId = null, ?int $userId = null): array
    {
        $settings = AppSetting::current();

        if (static::isTestMode($settings)) {
            $log = SmsLog::create([
                'customer_id' => $customerId,
                'phone' => $phone,
                'message' => $message,
                'status' => 'test',
                'provider_response' => 'Test mode: no gateway called.',
                'user_id' => $userId,
            ]);

            return ['ok' => true, 'status' => 'test', 'response' => $log->provider_response, 'log' => $log];
        }

        $responseText = null;
        $ok = false;

        try {
            $response = Http::timeout(15)->asForm()->post((string) $settings->sms_api_url, [
                'api_key' => (string) $settings->sms_api_key,
                'sender' => (string) ($settings->sms_sender ?? ''),
                'phone' => $phone,
                'message' => $message,
            ]);
            $ok = $response->successful();
            $body = static::limitText($response->body(), 2000);
            // v3.1.0 (M16): a 2xx that carries a JSON error signal is a
            // failure, not a sent message (see class docblock).
            if ($ok && static::looksLikeGatewayError($response->body())) {
                $ok = false;
            }
            $responseText = $ok ? $body : 'HTTP ' . $response->status() . ': ' . $body;
            if (! $ok && $response->successful()) {
                $responseText = 'Gateway reported failure (HTTP 2xx with error body): ' . $body;
            }
        } catch (Throwable $e) {
            // Gateway/transport failure is a log row, never an exception
            // bubbling up to the user (see class docblock).
            $ok = false;
            $responseText = static::limitText('Gateway error: ' . $e->getMessage(), 2000);
        }

        $log = SmsLog::create([
            'customer_id' => $customerId,
            'phone' => $phone,
            'message' => $message,
            'status' => $ok ? 'sent' : 'failed',
            'provider_response' => $responseText,
            'user_id' => $userId,
        ]);

        return ['ok' => $ok, 'status' => $log->status, 'response' => $responseText, 'log' => $log];
    }

    /**
     * Conservative, gateway-agnostic error signal: true ONLY when the
     * body parses as JSON and explicitly says it failed. Anything else
     * (plain text, HTML, an unrecognised JSON success shape, invalid
     * JSON) returns false, so unknown gateways keep their old 'sent'
     * behaviour. Checked keys, case-insensitively for status values:
     * status = error/failed/failure, success = false, or a non-empty
     * error/errors field.
     */
    private static function looksLikeGatewayError(?string $body): bool
    {
        if ($body === null || trim($body) === '') {
            return false;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            return false;
        }

        if (isset($data['status']) && is_string($data['status'])
            && in_array(strtolower(trim($data['status'])), ['error', 'failed', 'failure'], true)) {
            return true;
        }

        if (array_key_exists('success', $data) && $data['success'] === false) {
            return true;
        }

        if (! empty($data['error'])) {
            return true;
        }

        if (! empty($data['errors'])) {
            return true;
        }

        return false;
    }

    private static function limitText(?string $text, int $limit): string
    {
        $text = (string) ($text ?? '');
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }
}
