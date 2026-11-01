<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    // v3.1.0 (L4): credit_balance removed — the balance is ledger-
    // derived (increment/decrement + syncBalance()'s forceFill), never
    // request mass assignment. No controller create/update ever passed
    // credit_balance (rules() has no such field), so no call-site change
    // was needed for this model.
    // 2026-11-01: party_code IS fillable (it arrives on sync push from
    // the desktop), but it is never an editable web-form field — the
    // saving() hook below is the authority on its value, and
    // CustomerController::rules() deliberately has no party_code entry,
    // so a posted value is dropped by validation before it gets here.
    protected $fillable = [
        'name', 'phone', 'email', 'address', 'id_card_number',
        'current_address', 'permanent_address', 'image',
        'discount_percent', 'is_active', 'party_code',
    ];

    /** 2026-11-01 staff/party batch: party_code is system-assigned.
     *  Format P-0001 (4-digit zero-padded, grows past 9999 naturally).
     *  The server is the authority on conflicts, and this hook is what
     *  enforces it for every writer at once (web create, sync push
     *  create/update, seeder): a blank code is assigned the next free
     *  number, and a code already held by ANOTHER customer (soft-deleted
     *  rows included — the unique index covers them too) is reassigned
     *  to the next free number instead of failing the save. A code the
     *  row already owns passes through untouched.
     *
     *  Race note (deliberate, single-shop app): the next number is
     *  computed from the max suffix at save time. Two simultaneous
     *  creates can still compute the same number, and then the unique
     *  index throws instead of silently retrying — the caller sees the
     *  failure rather than the system quietly handing out a different
     *  code than the one it just reported. */
    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $code = trim((string) ($customer->party_code ?? ''));
            $takenByOther = $code !== '' && static::withTrashed()
                ->where('party_code', $code)
                ->when($customer->exists, fn ($q) => $q->where('id', '!=', $customer->id))
                ->exists();
            if ($code === '' || $takenByOther) {
                $customer->party_code = static::nextPartyCode();
            }
        });
    }

    /** Next free P-number: one past the largest numeric suffix among
     *  all codes matching P-<digits> (trashed rows included, since the
     *  unique index binds them too). Max + 1 is free by definition. */
    public static function nextPartyCode(): string
    {
        $max = 0;
        foreach (static::withTrashed()->whereNotNull('party_code')->pluck('party_code') as $code) {
            if (preg_match('/^P-(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return sprintf('P-%04d', $max + 1);
    }

    public function orders() { return $this->hasMany(Order::class); }
    public function payments() { return $this->hasMany(CustomerPayment::class); }

    /** The one true formula for what this customer owes:
     *      balance = (completed orders: total - paid)   [negative if overpaid]
     *              + manual debits - credits            [customer_payments]
     *  Positive = customer owes us, negative = advance/credit owed to them.
     *  Returned as an array so the ledger page can show every part. */
    public function ledgerFigures(): array
    {
        $orders = $this->orders()->where('status', 'completed');
        $billed = round((float) (clone $orders)->sum('total'), 2);
        $orderPaid = round((float) (clone $orders)->sum('paid_amount'), 2);
        $credits = round((float) $this->payments()->where('type', 'credit')->sum('amount'), 2);
        $debits = round((float) $this->payments()->where('type', 'debit')->sum('amount'), 2);
        $orderDue = round($billed - $orderPaid, 2);

        return [
            'total_billed' => $billed,
            'order_paid' => $orderPaid,
            'order_due' => $orderDue,
            'credits' => $credits,
            'debits' => $debits,
            'total_charges' => round($billed + $debits, 2),
            'total_paid' => round($orderPaid + $credits, 2),
            'balance' => round($orderDue + $debits - $credits, 2),
        ];
    }

    /** Re-saves credit_balance from ledgerFigures() if it drifted. */
    public function syncBalance(): array
    {
        $f = $this->ledgerFigures();
        if (abs((float) $this->credit_balance - $f['balance']) > 0.004) {
            $this->forceFill(['credit_balance' => $f['balance']])->save();
        }
        return $f;
    }

    /** v3.2.5: the DISPLAYED balance — ledgerFigures()['balance'] as a
     *  single float, for callers that only need the number (billing
     *  page, invoice). Never reads the stored credit_balance column:
     *  since v3.2.5 the stored column is only a reconciled cache, and
     *  every displayed balance is this recomputation, so the website
     *  and the desktop (which recomputes the same formula) agree. */
    public function recomputedBalance(): float
    {
        return (float) $this->ledgerFigures()['balance'];
    }

    /** v3.2.5: bulk recomputation of ledgerFigures()['balance'] for a
     *  set of customers, in exactly two grouped queries (no N+1 on a
     *  paginated list or a CSV export). Rounding mirrors
     *  ledgerFigures() step for step: each sum is rounded to 2dp
     *  first, then balance = round(orderDue + debits - credits, 2).
     *  Soft-deleted orders/payments are excluded by the models'
     *  default scope, exactly as ledgerFigures() excludes them
     *  through relations; unknown payment types contribute nothing.
     *  Returns [customer_id => balance]; ids with no history map to
     *  0.0. Used by the customers list, the customers CSV export,
     *  and the sync-push reconciliation below. */
    public static function recomputedBalancesForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $orderSums = Order::whereIn('customer_id', $ids)
            ->where('status', 'completed')
            ->selectRaw('customer_id, SUM(total) as billed, SUM(paid_amount) as paid')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $paymentSums = CustomerPayment::whereIn('customer_id', $ids)
            ->selectRaw('customer_id, type, SUM(amount) as amount')
            ->groupBy('customer_id', 'type')
            ->get()
            ->groupBy('customer_id');

        $balances = [];
        foreach ($ids as $id) {
            $orderRow = $orderSums->get($id);
            $billed = round((float) ($orderRow->billed ?? 0), 2);
            $orderPaid = round((float) ($orderRow->paid ?? 0), 2);
            $orderDue = round($billed - $orderPaid, 2);
            $credits = 0.0;
            $debits = 0.0;
            foreach ($paymentSums->get($id, collect()) as $row) {
                if ($row->type === 'credit') {
                    $credits = round((float) $row->amount, 2);
                } elseif ($row->type === 'debit') {
                    $debits = round((float) $row->amount, 2);
                }
            }
            $balances[$id] = round($orderDue + $debits - $credits, 2);
        }
        return $balances;
    }

    /** v3.2.5: sum of recomputed balances over ALL live customers, for
     *  Capital Receivables (ReportController::capital(),
     *  ExpenseController::index(), ReportExportController::capital()).
     *  This sums the per-customer rounded balances (via
     *  recomputedBalancesForIds), so the Capital figure ties exactly
     *  to the sum of the balances shown on the customers list — an
     *  aggregate-level rounding would drift by cents. Walk-in dues
     *  are NOT included here; callers add them exactly as before. */
    public static function totalRecomputedBalance(): float
    {
        $balances = self::recomputedBalancesForIds(self::query()->pluck('id')->all());
        return round(array_sum($balances), 2);
    }

    /** Falls back to a generated placeholder avatar if no image was uploaded. */
    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/customers/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=1e293b&color=fff&size=128';
    }
}
