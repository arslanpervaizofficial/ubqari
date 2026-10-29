<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ubqari POS v3.0 — Feature 6 (Full Accounting), as amended by v3.1.0
 * audit fixes H12, M11 and M12.
 *
 * The ONLY place journal rows are written. Every posting goes through
 * postEntry(), which:
 *   1. refuses to post the same source twice (source_type + source_id),
 *   2. drops zero/zero lines and rejects unknown account names,
 *   3. throws (and rolls back, via the DB transaction) if the remaining
 *      lines do not balance to the cent.
 *   4. (v3.1.0, M12) swallows a duplicate-key failure as "already
 *      posted" instead of crashing the caller, and logs every other
 *      posting failure with its source details (see postEntry()).
 *
 * Postings are triggered by model observers registered in
 * App\Providers\AccountingServiceProvider — no existing controller was
 * edited for this feature.
 *
 * CHART OF ACCOUNTS — these exact strings are the only account names
 * ever written to journal_lines.account:
 *   Cash, Bank, Inventory, Receivables, Supplier Payables,
 *   Sales Revenue, Sales Returns, COGS, Expenses, Capital, Cash Borrowing
 *
 * SIGN CONVENTION used by the reports (see AccountingController):
 *   - Assets (Cash, Bank, Inventory, Receivables) and costs (COGS,
 *     Expenses) are read as net DEBIT (debits minus credits).
 *   - Liabilities (Supplier Payables, Cash Borrowing), Capital and
 *     Sales Revenue are read as net CREDIT (credits minus debits).
 *   - Sales Returns is a contra-revenue account: it is read as a net
 *     DEBIT and SUBTRACTED from Sales Revenue in P&L / retained profit.
 *   - P&L net = Sales Revenue (net credit) - Sales Returns (net debit)
 *     - COGS (net debit) - Expenses (net debit).
 *
 * v3.1.0 REVERSALS (H12) — the journal is still append-only: nothing is
 * ever edited or deleted in journal_entries / journal_lines. A delete,
 * a completed→other status change, or a restore posts a NEW entry:
 *   - '<base>_reversal': the original legs flipped (delete, or a
 *     completed/received status moving back to another status).
 *   - '<base>_restore': the original legs again (Trash restore).
 *   - '<base>_edit': a single delta entry for an amount/type edit
 *     (new legs minus old legs, per account), posted with a NULL
 *     source_id so repeated edits to the same record can each post
 *     (the unique index on (source_type, source_id) allows multiple
 *     NULLs, and the record id is carried in the reference instead).
 * Reversal/restore pairs cover the FIRST delete/restore cycle only:
 * the (source_type, source_id) guard that stops double-posting also
 * stops a second reversal after a restore, so a second delete/restore
 * cycle posts nothing further (no crash, but the journal then stops
 * tracking that record — see the note on the journal page).
 *
 * v3.1.0 CUSTOMER PAYMENTS (M11) — CustomerPayment rows now post:
 *   - type=payment (when it exists): Dr Cash, Cr Receivables.
 *   - type=credit WITH a stock_return_id (created only by the stock
 *     return flow, which force-fills the link): a return credit —
 *     Dr Sales Returns, Cr Receivables.
 *   - type=credit WITHOUT a stock_return_id (the customer-ledger form):
 *     treated as a cash receipt — Dr Cash, Cr Receivables. This is the
 *     only reading the code supports: the ledger form never sets
 *     stock_return_id, and the return flow always does, so the link is
 *     the distinguishing rule. Known limit: a return adjustment typed
 *     in by hand on the ledger form (instead of through a stock return)
 *     has no link and is therefore posted as a receipt; the honest fix
 *     for that case is a separate return-adjustment entry point, not a
 *     guess in the observer.
 *   - type=debit (manual charge): NOT posted — a charge raises what the
 *     customer owes, but this chart has no honest second leg for it
 *     (it is not a sale, so it is not Sales Revenue).
 *
 * DELIBERATE EXCLUSIONS (unchanged, see NOTES.txt for the full reasoning):
 *   - SupplierPayment types credit/debit: NOT auto-posted. Only
 *     type=payment posts (Dr Supplier Payables, Cr Cash). A credit note
 *     or extra charge has no goods link in this ledger, so there is no
 *     honest second leg to post against without inventing one.
 *   - Expense type cash_in (capital injection): NOT auto-posted. Only
 *     type=cash_out posts (Dr Expenses, Cr Cash).
 *   - CashTransaction types other than borrow/repay: NOT posted.
 *   - OrderPayment type debit: NOT posted (a ledger correction/charge
 *     with no cash movement; posting Cr Cash would invent cash out).
 *   - Stock returns themselves: NO direct journal entry. A
 *     from_customer return reaches the journal only through the linked
 *     CustomerPayment credit it creates (Dr Sales Returns, Cr
 *     Receivables); its Inventory/COGS legs are NOT posted, and a
 *     to_supplier return posts nothing at all. Stock-count adjustments
 *     likewise post nothing.
 *   - Order/PO edits that keep the status completed/received (item or
 *     total corrections): NOT posted — only the status transitions,
 *     deletes and restores are journalled (see the unposted list on the
 *     journal page for the full list).
 *   - Permanent (force) deletes from Trash: NOT journalled — Eloquent
 *     fires forceDeleted, not deleted, so no observer here sees them.
 *   - Business from before this feature was installed: no journal
 *     entries exist for it (see AccountingController's NO BACKFILL
 *     note); use the opening-balance form instead.
 */
class AccountingService
{
    /** The full chart of accounts — use exactly these strings. */
    public const ACCOUNTS = [
        'Cash',
        'Bank',
        'Inventory',
        'Receivables',
        'Supplier Payables',
        'Sales Revenue',
        // v3.1.0 (M11): contra-revenue for customer-return credits.
        'Sales Returns',
        'COGS',
        'Expenses',
        'Capital',
        'Cash Borrowing',
    ];

    // ------------------------------------------------------------------
    // Core posting primitive
    // ------------------------------------------------------------------

    /** True when an entry already exists for this source event. */
    public function alreadyPosted(string $sourceType, ?int $sourceId): bool
    {
        if ($sourceId === null) {
            return false;
        }

        return JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * Create one balanced journal entry with its lines.
     *
     * @param  array  $lines  each: ['account' => string, 'debit' => float, 'credit' => float]
     * @return JournalEntry|null  null when this source was already posted (skipped, not an error)
     *
     * @throws \InvalidArgumentException  unknown account name, or no usable lines
     * @throws \RuntimeException          lines do not balance
     *
     * v3.1.0 (M12): a duplicate-key failure at insert time (a race
     * between the alreadyPosted() check and the insert, or a second
     * reversal/restore cycle hitting the unique index) is treated as
     * "already posted" and returns null instead of throwing. Every
     * other failure is logged with its source details and re-thrown.
     */
    public function postEntry(
        string $sourceType,
        ?int $sourceId,
        string $entryDate,
        ?string $reference,
        ?string $description,
        ?int $userId,
        array $lines,
    ): ?JournalEntry {
        if ($this->alreadyPosted($sourceType, $sourceId)) {
            return null;
        }

        $clean = [];
        foreach ($lines as $line) {
            $account = $line['account'] ?? null;
            if (!in_array($account, self::ACCOUNTS, true)) {
                throw new \InvalidArgumentException("Unknown journal account: {$account}");
            }
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            // A line with both sides zero carries no information; drop it
            // so a zero-amount event cannot produce an empty entry.
            if (abs($debit) <= 0.004 && abs($credit) <= 0.004) {
                continue;
            }
            // A line must not carry both a debit and a credit: keep the
            // net side so every line reads cleanly in the journal view.
            if ($debit > 0 && $credit > 0) {
                if ($debit >= $credit) {
                    $debit = round($debit - $credit, 2);
                    $credit = 0.0;
                } else {
                    $credit = round($credit - $debit, 2);
                    $debit = 0.0;
                }
                if (abs($debit) <= 0.004 && abs($credit) <= 0.004) {
                    continue;
                }
            }
            $clean[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit];
        }

        if (count($clean) === 0) {
            throw new \InvalidArgumentException('Refusing to post a journal entry with no non-zero lines.');
        }

        $totalDebit = round(array_sum(array_column($clean, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($clean, 'credit')), 2);
        if (abs($totalDebit - $totalCredit) > 0.004) {
            throw new \RuntimeException(
                "Unbalanced journal entry refused ({$reference}): debits {$totalDebit} != credits {$totalCredit}."
            );
        }

        try {
            return DB::transaction(function () use ($sourceType, $sourceId, $entryDate, $reference, $description, $userId, $clean) {
                $entry = JournalEntry::create([
                    'entry_date' => $entryDate,
                    'reference' => $reference,
                    'description' => $description,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'user_id' => $userId,
                ]);
                foreach ($clean as $line) {
                    $entry->lines()->create($line);
                }

                return $entry;
            });
        } catch (QueryException $e) {
            // Duplicate (source_type, source_id): another process posted
            // this event first, or a repeat reversal/restore cycle hit
            // the unique index. Either way the event is already in the
            // journal, so this is a skip, not an error — and the caller
            // (an observer) must not crash the business action.
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::warning('Journal posting skipped as already posted (duplicate key)', [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'reference' => $reference,
                ]);

                return null;
            }
            Log::error('Journal posting failed', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reference' => $reference,
                'description' => $description,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Journal posting failed', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reference' => $reference,
                'description' => $description,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Reversal / restore / edit-delta helpers (v3.1.0, H12)
    // ------------------------------------------------------------------

    /** Flip every line's debit/credit sides. */
    private function flipLines(array $lines): array
    {
        return array_map(fn ($line) => [
            'account' => $line['account'],
            'debit' => round((float) ($line['credit'] ?? 0), 2),
            'credit' => round((float) ($line['debit'] ?? 0), 2),
        ], $lines);
    }

    /** Lines of the already-posted entry for a source, or null. */
    private function journalLinesFor(string $sourceType, int $sourceId): ?array
    {
        $entry = JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->with('lines')
            ->first();
        if (!$entry) {
            return null;
        }

        return $entry->lines->map(fn ($line) => [
            'account' => $line->account,
            'debit' => round((float) $line->debit, 2),
            'credit' => round((float) $line->credit, 2),
        ])->all();
    }

    /**
     * Post the flipped legs of an already-posted entry as
     * '<base>_reversal'. Returns null when there is nothing to reverse
     * (never posted) or a reversal already exists (first cycle only —
     * see the class docblock).
     */
    public function reverseFromJournal(
        string $baseSourceType,
        int $sourceId,
        string $reference,
        ?string $description,
        ?int $userId,
    ): ?JournalEntry {
        if (!$this->alreadyPosted($baseSourceType, $sourceId)) {
            return null;
        }
        if ($this->alreadyPosted($baseSourceType . '_reversal', $sourceId)) {
            return null;
        }
        $lines = $this->journalLinesFor($baseSourceType, $sourceId);
        if ($lines === null || count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            $baseSourceType . '_reversal',
            $sourceId,
            now()->toDateString(),
            $reference . ' (reversal)',
            $description ? 'Reversal: ' . $description : 'Reversal',
            $userId,
            $this->flipLines($lines),
        );
    }

    /**
     * Post the original legs again as '<base>_restore' (the inverse of
     * a reversal). Returns null when no reversal exists or a restore
     * already exists (first cycle only — see the class docblock).
     */
    public function restoreFromJournal(
        string $baseSourceType,
        int $sourceId,
        string $reference,
        ?string $description,
        ?int $userId,
    ): ?JournalEntry {
        if (!$this->alreadyPosted($baseSourceType . '_reversal', $sourceId)) {
            return null;
        }
        if ($this->alreadyPosted($baseSourceType . '_restore', $sourceId)) {
            return null;
        }
        $lines = $this->journalLinesFor($baseSourceType . '_reversal', $sourceId);
        if ($lines === null || count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            $baseSourceType . '_restore',
            $sourceId,
            now()->toDateString(),
            $reference . ' (restored)',
            $description ? 'Restored: ' . $description : 'Restored',
            $userId,
            $this->flipLines($lines),
        );
    }

    /**
     * Post a single delta entry for an amount/type edit: per account,
     * (new net debit) minus (old net debit), converted back to
     * debit/credit lines. A delta of two balanced line sets is balanced
     * by construction. source_id is NULL (repeatable across edits);
     * the record id is carried in the reference.
     */
    public function postEditDelta(
        string $baseSourceType,
        int $sourceId,
        array $oldLines,
        array $newLines,
        string $reference,
        ?string $description,
        ?int $userId,
    ): ?JournalEntry {
        $net = function (array $lines): array {
            $out = [];
            foreach ($lines as $line) {
                $account = $line['account'];
                $out[$account] = round(
                    ($out[$account] ?? 0.0) + (float) ($line['debit'] ?? 0) - (float) ($line['credit'] ?? 0),
                    2
                );
            }

            return $out;
        };

        $old = $net($oldLines);
        $new = $net($newLines);
        $delta = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $account) {
            $d = round(($new[$account] ?? 0.0) - ($old[$account] ?? 0.0), 2);
            if ($d > 0.004) {
                $delta[] = ['account' => $account, 'debit' => $d, 'credit' => 0];
            } elseif ($d < -0.004) {
                $delta[] = ['account' => $account, 'debit' => 0, 'credit' => abs($d)];
            }
        }

        if (count($delta) === 0) {
            return null;
        }

        return $this->postEntry(
            $baseSourceType . '_edit',
            null,
            now()->toDateString(),
            $reference . ' (edit)',
            $description ? 'Edit adjustment: ' . $description : 'Edit adjustment',
            $userId,
            $delta,
        );
    }

    // ------------------------------------------------------------------
    // Line builders (one per event; post methods below only wrap them
    // with a date, reference and description)
    // ------------------------------------------------------------------

    /**
     * Completed sale lines. Payment split: the order's stored
     * cash_amount / bank_amount are used when both are actually
     * present; otherwise the split is derived from payment_method +
     * paid_amount (cash => all cash, bank => all bank, split without
     * stored parts => all cash — see NOTES.txt for why the stored
     * values cannot be relied on alone). The Receivables line is then
     * total minus cash minus bank, which equals due_amount whenever
     * paid + due = total (the normal case) and keeps the entry
     * balanced by construction even when the stored figures disagree.
     * A walk-in (no customer) overpayment is change handed back, so
     * cash/bank are capped at the order total; a named customer's
     * overpayment is kept as a Cr Receivables line (an advance on
     * their account), which also balances.
     *
     * COGS APPROXIMATION (state this wherever the figures are shown):
     * cost = sum over items of quantity x the product's CURRENT net
     * purchase price (Product::net_purchase_price — purchase_price
     * after purchase_discount_percent, exactly as the margin report
     * computes it). No historical cost snapshot is stored per sale, so
     * if a product's cost is edited after the sale, this entry's COGS
     * reflects the cost at posting time, not at sale time. Reversals
     * read the ORIGINAL entry's lines back (see reverseFromJournal),
     * so a later cost edit cannot make a reversal drift from its sale.
     */
    public function saleLines(Order $order): array
    {
        $total = round((float) $order->total, 2);
        $paid = round((float) $order->paid_amount, 2);

        $storedCash = $order->getAttribute('cash_amount');
        $storedBank = $order->getAttribute('bank_amount');
        if ($order->payment_method === 'split' && $storedCash !== null && $storedBank !== null) {
            $cash = round((float) $storedCash, 2);
            $bank = round((float) $storedBank, 2);
        } elseif ($order->payment_method === 'bank') {
            $cash = 0.0;
            $bank = $paid;
        } else {
            $cash = $paid;
            $bank = 0.0;
        }

        if (!$order->customer_id && $cash + $bank > $total) {
            $excess = round($cash + $bank - $total, 2);
            $cutCash = min($cash, $excess);
            $cash = round($cash - $cutCash, 2);
            $bank = round($bank - ($excess - $cutCash), 2);
        }

        $receivables = round($total - $cash - $bank, 2);

        $cogs = 0.0;
        foreach ($order->items()->with('product')->get() as $item) {
            if (!$item->product) {
                continue;
            }
            $cogs += (float) $item->quantity * (float) $item->product->net_purchase_price;
        }
        $cogs = round($cogs, 2);

        if ($total <= 0 && $cogs <= 0) {
            return [];
        }

        $lines = [];
        if ($cash > 0) {
            $lines[] = ['account' => 'Cash', 'debit' => $cash, 'credit' => 0];
        }
        if ($bank > 0) {
            $lines[] = ['account' => 'Bank', 'debit' => $bank, 'credit' => 0];
        }
        if ($receivables > 0.004) {
            $lines[] = ['account' => 'Receivables', 'debit' => $receivables, 'credit' => 0];
        } elseif ($receivables < -0.004) {
            $lines[] = ['account' => 'Receivables', 'debit' => 0, 'credit' => abs($receivables)];
        }
        if ($total > 0) {
            $lines[] = ['account' => 'Sales Revenue', 'debit' => 0, 'credit' => $total];
        }
        if ($cogs > 0) {
            $lines[] = ['account' => 'COGS', 'debit' => $cogs, 'credit' => 0];
            $lines[] = ['account' => 'Inventory', 'debit' => 0, 'credit' => $cogs];
        }

        return $lines;
    }

    /** Expense cash_out lines; any other type (cash_in) => []. */
    public function expenseLinesFor(string $type, float $amount): array
    {
        if ($type !== 'cash_out' || round($amount, 2) <= 0) {
            return [];
        }
        $amount = round($amount, 2);

        return [
            ['account' => 'Expenses', 'debit' => $amount, 'credit' => 0],
            ['account' => 'Cash', 'debit' => 0, 'credit' => $amount],
        ];
    }

    /** PO received lines; a zero/negative total => []. */
    public function purchaseOrderLines(PurchaseOrder $purchaseOrder): array
    {
        $total = round((float) $purchaseOrder->total, 2);
        if ($total <= 0) {
            return [];
        }

        return [
            ['account' => 'Inventory', 'debit' => $total, 'credit' => 0],
            ['account' => 'Supplier Payables', 'debit' => 0, 'credit' => $total],
        ];
    }

    /** Supplier payment lines; only type=payment posts. */
    public function supplierPaymentLinesFor(string $type, float $amount): array
    {
        if ($type !== 'payment' || round($amount, 2) <= 0) {
            return [];
        }
        $amount = round($amount, 2);

        return [
            ['account' => 'Supplier Payables', 'debit' => $amount, 'credit' => 0],
            ['account' => 'Cash', 'debit' => 0, 'credit' => $amount],
        ];
    }

    /**
     * Customer payment lines (v3.1.0, M11 — see the class docblock for
     * the distinguishing rule and its known limit).
     *   payment            => Dr Cash, Cr Receivables
     *   credit + return id => Dr Sales Returns, Cr Receivables
     *   credit, no return  => Dr Cash, Cr Receivables (receipt)
     *   debit              => [] (no honest second leg)
     */
    public function customerPaymentLinesFor(string $type, $stockReturnId, float $amount): array
    {
        if (round($amount, 2) <= 0) {
            return [];
        }
        $amount = round($amount, 2);

        if ($type === 'payment') {
            return [
                ['account' => 'Cash', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Receivables', 'debit' => 0, 'credit' => $amount],
            ];
        }
        if ($type === 'credit') {
            if ($stockReturnId !== null) {
                return [
                    ['account' => 'Sales Returns', 'debit' => $amount, 'credit' => 0],
                    ['account' => 'Receivables', 'debit' => 0, 'credit' => $amount],
                ];
            }

            return [
                ['account' => 'Cash', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Receivables', 'debit' => 0, 'credit' => $amount],
            ];
        }

        return [];
    }

    /** Cash borrow/repay lines; any other type => []. */
    public function cashTransactionLinesFor(string $type, float $amount): array
    {
        if (!in_array($type, ['borrow', 'repay'], true) || round($amount, 2) <= 0) {
            return [];
        }
        $amount = round($amount, 2);

        return $type === 'borrow'
            ? [
                ['account' => 'Cash', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Cash Borrowing', 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account' => 'Cash Borrowing', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Cash', 'debit' => 0, 'credit' => $amount],
            ];
    }

    /**
     * Order-ledger payment lines (v3.1.0, H12): a CREDIT recorded
     * against an already-COMPLETED order collects part of the
     * receivable the sale entry left open — Dr Cash, Cr Receivables.
     * Payments on in_progress orders are NOT posted here: they are
     * already inside the sale entry's paid split at completion.
     * A debit ledger row is NOT posted (no cash movement).
     */
    public function orderPaymentLinesFor(OrderPayment $payment): array
    {
        if ($payment->type !== 'credit' || round((float) $payment->amount, 2) <= 0) {
            return [];
        }
        $order = $payment->order;
        if (!$order || $order->status !== 'completed') {
            return [];
        }
        $amount = round((float) $payment->amount, 2);

        return [
            ['account' => 'Cash', 'debit' => $amount, 'credit' => 0],
            ['account' => 'Receivables', 'debit' => 0, 'credit' => $amount],
        ];
    }

    // ------------------------------------------------------------------
    // Event postings (called by the observers, one entry per event)
    // ------------------------------------------------------------------

    /** Completed sale — ONE entry (see saleLines() for the legs). */
    public function postSale(Order $order, string $sourceType = 'order'): ?JournalEntry
    {
        $lines = $this->saleLines($order);
        if (count($lines) === 0) {
            return null;
        }

        $date = $order->original_completed_at
            ? $order->original_completed_at->toDateString()
            : ($order->updated_at ? $order->updated_at->toDateString() : now()->toDateString());

        return $this->postEntry(
            $sourceType,
            (int) $order->id,
            $date,
            'Order #' . ($order->order_number ?: $order->id),
            'Sale completed' . ($order->customer_id ? ' (customer #' . $order->customer_id . ')' : ' (walk-in)'),
            $order->user_id,
            $lines,
        );
    }

    /** Reversal of a completed sale (delete, or completed→other). */
    public function reverseSale(Order $order): ?JournalEntry
    {
        return $this->reverseFromJournal(
            'order',
            (int) $order->id,
            'Order #' . ($order->order_number ?: $order->id),
            'Sale completed' . ($order->customer_id ? ' (customer #' . $order->customer_id . ')' : ' (walk-in)'),
            $order->user_id,
        );
    }

    /** Re-posting of a reversed sale (Trash restore). */
    public function restoreSale(Order $order): ?JournalEntry
    {
        return $this->restoreFromJournal(
            'order',
            (int) $order->id,
            'Order #' . ($order->order_number ?: $order->id),
            'Sale completed' . ($order->customer_id ? ' (customer #' . $order->customer_id . ')' : ' (walk-in)'),
            $order->user_id,
        );
    }

    /** Expense cash_out: Dr Expenses, Cr Cash. cash_in is NOT posted. */
    public function postExpense(Expense $expense, string $sourceType = 'expense'): ?JournalEntry
    {
        $lines = $this->expenseLinesFor($expense->type, (float) $expense->amount);
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            $sourceType,
            (int) $expense->id,
            $expense->expense_date ? $expense->expense_date->toDateString() : now()->toDateString(),
            'Expense #' . $expense->id,
            trim(($expense->category ? $expense->category . ' — ' : '') . ($expense->note ?? '')) ?: 'Expense',
            $expense->user_id,
            $lines,
        );
    }

    /** Reversal of a posted expense (delete). Lines are recomputed from
     *  the current row, so an edit followed by a delete still reverses
     *  the net posted amount (original + edit deltas). */
    public function reverseExpense(Expense $expense): ?JournalEntry
    {
        if (!$this->alreadyPosted('expense', (int) $expense->id)) {
            return null;
        }
        if ($this->alreadyPosted('expense_reversal', (int) $expense->id)) {
            return null;
        }
        $lines = $this->expenseLinesFor($expense->type, (float) $expense->amount);
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            'expense_reversal',
            (int) $expense->id,
            now()->toDateString(),
            'Expense #' . $expense->id . ' (reversal)',
            'Reversal: ' . (trim(($expense->category ? $expense->category . ' — ' : '') . ($expense->note ?? '')) ?: 'Expense'),
            $expense->user_id,
            $this->flipLines($lines),
        );
    }

    /** Re-posting of a reversed expense (Trash restore). */
    public function restoreExpense(Expense $expense): ?JournalEntry
    {
        if (!$this->alreadyPosted('expense_reversal', (int) $expense->id)) {
            return null;
        }
        if ($this->alreadyPosted('expense_restore', (int) $expense->id)) {
            return null;
        }

        return $this->postExpense($expense, 'expense_restore');
    }

    /** PO received: Dr Inventory, Cr Supplier Payables (PO total). */
    public function postPurchaseOrder(PurchaseOrder $purchaseOrder, string $sourceType = 'purchase_order'): ?JournalEntry
    {
        $lines = $this->purchaseOrderLines($purchaseOrder);
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            $sourceType,
            (int) $purchaseOrder->id,
            $purchaseOrder->updated_at ? $purchaseOrder->updated_at->toDateString() : now()->toDateString(),
            'PO #' . $purchaseOrder->id,
            'Purchase order received' . ($purchaseOrder->supplier_id ? ' (supplier #' . $purchaseOrder->supplier_id . ')' : ''),
            auth()->id(),
            $lines,
        );
    }

    /** Reversal of a received PO (delete, or received→other). */
    public function reversePurchaseOrder(PurchaseOrder $purchaseOrder): ?JournalEntry
    {
        return $this->reverseFromJournal(
            'purchase_order',
            (int) $purchaseOrder->id,
            'PO #' . $purchaseOrder->id,
            'Purchase order received' . ($purchaseOrder->supplier_id ? ' (supplier #' . $purchaseOrder->supplier_id . ')' : ''),
            auth()->id(),
        );
    }

    /** Re-posting of a reversed PO (Trash restore). */
    public function restorePurchaseOrder(PurchaseOrder $purchaseOrder): ?JournalEntry
    {
        return $this->restoreFromJournal(
            'purchase_order',
            (int) $purchaseOrder->id,
            'PO #' . $purchaseOrder->id,
            'Purchase order received' . ($purchaseOrder->supplier_id ? ' (supplier #' . $purchaseOrder->supplier_id . ')' : ''),
            auth()->id(),
        );
    }

    /**
     * Supplier payment: Dr Supplier Payables, Cr Cash.
     * Types credit/debit are NOT auto-posted (see class docblock).
     */
    public function postSupplierPayment(SupplierPayment $payment, string $sourceType = 'supplier_payment'): ?JournalEntry
    {
        $lines = $this->supplierPaymentLinesFor($payment->type, (float) $payment->amount);
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            $sourceType,
            (int) $payment->id,
            $payment->created_at ? $payment->created_at->toDateString() : now()->toDateString(),
            'Supplier Payment #' . $payment->id,
            trim('Payment to supplier #' . $payment->supplier_id . ($payment->note ? ' — ' . $payment->note : '')),
            $payment->user_id,
            $lines,
        );
    }

    /** Reversal of a posted supplier payment (delete). */
    public function reverseSupplierPayment(SupplierPayment $payment): ?JournalEntry
    {
        if (!$this->alreadyPosted('supplier_payment', (int) $payment->id)) {
            return null;
        }
        if ($this->alreadyPosted('supplier_payment_reversal', (int) $payment->id)) {
            return null;
        }
        $lines = $this->supplierPaymentLinesFor($payment->type, (float) $payment->amount);
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            'supplier_payment_reversal',
            (int) $payment->id,
            now()->toDateString(),
            'Supplier Payment #' . $payment->id . ' (reversal)',
            'Reversal: Payment to supplier #' . $payment->supplier_id,
            $payment->user_id,
            $this->flipLines($lines),
        );
    }

    /** Re-posting of a reversed supplier payment (Trash restore). */
    public function restoreSupplierPayment(SupplierPayment $payment): ?JournalEntry
    {
        if (!$this->alreadyPosted('supplier_payment_reversal', (int) $payment->id)) {
            return null;
        }
        if ($this->alreadyPosted('supplier_payment_restore', (int) $payment->id)) {
            return null;
        }

        return $this->postSupplierPayment($payment, 'supplier_payment_restore');
    }

    /**
     * Customer payment (v3.1.0, M11): posts type=payment, type=credit
     * receipts and type=credit return credits — see
     * customerPaymentLinesFor() for the legs and the distinguishing
     * rule. type=debit is NOT posted.
     */
    public function postCustomerPayment(CustomerPayment $payment, string $sourceType = 'customer_payment'): ?JournalEntry
    {
        $lines = $this->customerPaymentLinesFor(
            $payment->type,
            $payment->getAttribute('stock_return_id'),
            (float) $payment->amount,
        );
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            $sourceType,
            (int) $payment->id,
            $payment->created_at ? $payment->created_at->toDateString() : now()->toDateString(),
            'Customer Payment #' . $payment->id,
            trim('Payment from customer #' . $payment->customer_id . ($payment->note ? ' — ' . $payment->note : '')),
            $payment->user_id,
            $lines,
        );
    }

    /** Reversal of a posted customer payment (delete). */
    public function reverseCustomerPayment(CustomerPayment $payment): ?JournalEntry
    {
        if (!$this->alreadyPosted('customer_payment', (int) $payment->id)) {
            return null;
        }
        if ($this->alreadyPosted('customer_payment_reversal', (int) $payment->id)) {
            return null;
        }
        $lines = $this->customerPaymentLinesFor(
            $payment->type,
            $payment->getAttribute('stock_return_id'),
            (float) $payment->amount,
        );
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            'customer_payment_reversal',
            (int) $payment->id,
            now()->toDateString(),
            'Customer Payment #' . $payment->id . ' (reversal)',
            'Reversal: Payment from customer #' . $payment->customer_id,
            $payment->user_id,
            $this->flipLines($lines),
        );
    }

    /** Re-posting of a reversed customer payment (Trash restore). */
    public function restoreCustomerPayment(CustomerPayment $payment): ?JournalEntry
    {
        if (!$this->alreadyPosted('customer_payment_reversal', (int) $payment->id)) {
            return null;
        }
        if ($this->alreadyPosted('customer_payment_restore', (int) $payment->id)) {
            return null;
        }

        return $this->postCustomerPayment($payment, 'customer_payment_restore');
    }

    /**
     * Cash borrow: Dr Cash, Cr Cash Borrowing.
     * Cash repay:  Dr Cash Borrowing, Cr Cash.
     */
    public function postCashTransaction(CashTransaction $transaction, string $sourceType = 'cash_transaction'): ?JournalEntry
    {
        $lines = $this->cashTransactionLinesFor($transaction->type, (float) $transaction->amount);
        if (count($lines) === 0) {
            return null;
        }

        $party = $transaction->party ? $transaction->party->name : ('party #' . $transaction->cash_party_id);

        return $this->postEntry(
            $sourceType,
            (int) $transaction->id,
            $transaction->transaction_date ? $transaction->transaction_date->toDateString() : now()->toDateString(),
            'Cash Transaction #' . $transaction->id,
            ($transaction->type === 'borrow' ? 'Borrowed from ' : 'Repayment to ') . $party
                . ($transaction->note ? ' — ' . $transaction->note : ''),
            $transaction->user_id,
            $lines,
        );
    }

    /** Reversal of a posted cash transaction (delete). */
    public function reverseCashTransaction(CashTransaction $transaction): ?JournalEntry
    {
        if (!$this->alreadyPosted('cash_transaction', (int) $transaction->id)) {
            return null;
        }
        if ($this->alreadyPosted('cash_transaction_reversal', (int) $transaction->id)) {
            return null;
        }
        $lines = $this->cashTransactionLinesFor($transaction->type, (float) $transaction->amount);
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            'cash_transaction_reversal',
            (int) $transaction->id,
            now()->toDateString(),
            'Cash Transaction #' . $transaction->id . ' (reversal)',
            'Reversal: Cash transaction #' . $transaction->id,
            $transaction->user_id,
            $this->flipLines($lines),
        );
    }

    /** Re-posting of a reversed cash transaction (Trash restore). */
    public function restoreCashTransaction(CashTransaction $transaction): ?JournalEntry
    {
        if (!$this->alreadyPosted('cash_transaction_reversal', (int) $transaction->id)) {
            return null;
        }
        if ($this->alreadyPosted('cash_transaction_restore', (int) $transaction->id)) {
            return null;
        }

        return $this->postCashTransaction($transaction, 'cash_transaction_restore');
    }

    /**
     * Order-ledger payment collected after completion (v3.1.0, H12):
     * Dr Cash, Cr Receivables. See orderPaymentLinesFor() for why
     * in_progress payments and debit rows are skipped. Note: the
     * gateway settlement behind an online payment has no cash/bank
     * split on the OrderPayment row, so it posts to Cash here.
     */
    public function postOrderPayment(OrderPayment $payment): ?JournalEntry
    {
        $lines = $this->orderPaymentLinesFor($payment);
        if (count($lines) === 0) {
            return null;
        }

        return $this->postEntry(
            'order_payment',
            (int) $payment->id,
            $payment->created_at ? $payment->created_at->toDateString() : now()->toDateString(),
            'Order Payment #' . $payment->id,
            trim('Payment collected for order #' . $payment->order_id . ($payment->note ? ' — ' . $payment->note : '')),
            $payment->user_id,
            $lines,
        );
    }

    /**
     * Manual opening-balance entry (admin form). The caller validates
     * the shape; this method re-checks balance via postEntry() and
     * throws on any imbalance, so a bad form can never write a
     * half-balanced entry. source_id is null, so every submission is
     * a new entry (no duplicate guard — opening balances are entered
     * once by the admin, deliberately, not by an automated event).
     */
    public function postOpeningBalance(
        string $entryDate,
        ?string $description,
        ?int $userId,
        array $lines,
    ): ?JournalEntry {
        return $this->postEntry(
            'opening_balance',
            null,
            $entryDate,
            'Opening Balance',
            $description ?: 'Opening balance',
            $userId,
            $lines,
        );
    }

    // ------------------------------------------------------------------
    // Report helpers (journal only — never the operational tables)
    // ------------------------------------------------------------------

    /**
     * Per-account debit/credit totals for entries in the date window.
     * Pass null $from/$to for all-time. Each row:
     *   ['debit' => float, 'credit' => float, 'netDebit' => debit - credit]
     * Accounts with no lines in the window are returned as zeroes.
     */
    public function accountTotals(?string $from = null, ?string $to = null): array
    {
        $totals = [];
        foreach (self::ACCOUNTS as $account) {
            $totals[$account] = ['debit' => 0.0, 'credit' => 0.0, 'netDebit' => 0.0];
        }

        $query = JournalEntry::query()
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->selectRaw('journal_lines.account, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->groupBy('journal_lines.account');
        if ($from) {
            $query->whereDate('journal_entries.entry_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('journal_entries.entry_date', '<=', $to);
        }

        foreach ($query->get() as $row) {
            if (!isset($totals[$row->account])) {
                continue;
            }
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);
            $totals[$row->account] = [
                'debit' => $debit,
                'credit' => $credit,
                'netDebit' => round($debit - $credit, 2),
            ];
        }

        return $totals;
    }
}
