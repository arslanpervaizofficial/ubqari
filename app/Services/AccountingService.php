<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;

/**
 * Ubqari POS v3.0 — Feature 6 (Full Accounting).
 *
 * The ONLY place journal rows are written. Every posting goes through
 * postEntry(), which:
 *   1. refuses to post the same source twice (source_type + source_id),
 *   2. drops zero/zero lines and rejects unknown account names,
 *   3. throws (and rolls back, via the DB transaction) if the remaining
 *      lines do not balance to the cent.
 *
 * Postings are triggered by model observers registered in
 * App\Providers\AccountingServiceProvider — no existing controller was
 * edited for this feature.
 *
 * CHART OF ACCOUNTS — these exact strings are the only account names
 * ever written to journal_lines.account:
 *   Cash, Bank, Inventory, Receivables, Supplier Payables,
 *   Sales Revenue, COGS, Expenses, Capital, Cash Borrowing
 *
 * SIGN CONVENTION used by the reports (see AccountingController):
 *   - Assets (Cash, Bank, Inventory, Receivables) and costs (COGS,
 *     Expenses) are read as net DEBIT (debits minus credits).
 *   - Liabilities (Supplier Payables, Cash Borrowing), Capital and
 *     Sales Revenue are read as net CREDIT (credits minus debits).
 *   - P&L net = Sales Revenue (net credit) - COGS (net debit)
 *     - Expenses (net debit).
 *
 * DELIBERATE EXCLUSIONS (see NOTES.txt for the full reasoning):
 *   - SupplierPayment types credit/debit: NOT auto-posted. Only
 *     type=payment posts (Dr Supplier Payables, Cr Cash). A credit note
 *     or extra charge has no goods link in this ledger, so there is no
 *     honest second leg to post against without inventing one.
 *   - CustomerPayment types credit/debit: NOT auto-posted. Only
 *     type=payment posts (Dr Cash, Cr Receivables) — and note that the
 *     base app's CustomerPayment type column is an enum of
 *     credit/debit only, so in practice no CustomerPayment auto-posts
 *     until a type=payment value exists (see NOTES.txt).
 *   - Expense type cash_in (capital injection): NOT auto-posted. Only
 *     type=cash_out posts (Dr Expenses, Cr Cash).
 *   - Stock returns, order edits/deletes, PO deletes, payment edits and
 *     Trash restores: NO journal reversal is posted. The journal is an
 *     append-only record of what was posted; reversing entries are a
 *     separate feature (see NOTES.txt).
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
    }

    // ------------------------------------------------------------------
    // Event postings (called by the observers, one entry per event)
    // ------------------------------------------------------------------

    /**
     * Completed sale — ONE entry with up to 6 lines:
     *   Dr Cash / Dr Bank / Dr Receivables (or Cr Receivables for an
     *     overpayment advance), Cr Sales Revenue (order total),
     *   Dr COGS, Cr Inventory (cost of the goods sold).
     *
     * Payment split: the order's stored cash_amount / bank_amount are
     * used when both are actually present; otherwise the split is
     * derived from payment_method + paid_amount (cash => all cash,
     * bank => all bank, split without stored parts => all cash — see
     * NOTES.txt for why the stored values cannot be relied on alone).
     * The Receivables line is then total minus cash minus bank, which
     * equals due_amount whenever paid + due = total (the normal case)
     * and keeps the entry balanced by construction even when the
     * stored figures disagree. A walk-in (no customer) overpayment is
     * change handed back, so cash/bank are capped at the order total;
     * a named customer's overpayment is kept as a Cr Receivables line
     * (an advance on their account), which also balances.
     *
     * COGS APPROXIMATION (state this wherever the figures are shown):
     * cost = sum over items of quantity x the product's CURRENT net
     * purchase price (Product::net_purchase_price — purchase_price
     * after purchase_discount_percent, exactly as the margin report
     * computes it). No historical cost snapshot is stored per sale, so
     * if a product's cost is edited after the sale, this entry's COGS
     * reflects the cost at posting time, not at sale time.
     */
    public function postSale(Order $order): ?JournalEntry
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
            // 'cash', 'split' without stored parts, or an unknown method:
            // everything actually taken goes to Cash.
            $cash = $paid;
            $bank = 0.0;
        }

        // Walk-in overpayment is change, not money kept: cap cash/bank
        // at the order total (cash first, then bank).
        if (!$order->customer_id && $cash + $bank > $total) {
            $excess = round($cash + $bank - $total, 2);
            $cutCash = min($cash, $excess);
            $cash = round($cash - $cutCash, 2);
            $bank = round($bank - ($excess - $cutCash), 2);
        }

        // Signed receivables: positive => customer still owes (Dr),
        // negative => customer overpaid, an advance (Cr). Equals
        // due_amount in every normal case (paid + due = total).
        $receivables = round($total - $cash - $bank, 2);

        $cogs = 0.0;
        foreach ($order->items()->with('product')->get() as $item) {
            if (!$item->product) {
                continue; // product deleted since the sale: no cost known
            }
            $cogs += (float) $item->quantity * (float) $item->product->net_purchase_price;
        }
        $cogs = round($cogs, 2);

        if ($total <= 0 && $cogs <= 0) {
            return null; // nothing of value to post
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

        $date = $order->original_completed_at
            ? $order->original_completed_at->toDateString()
            : ($order->updated_at ? $order->updated_at->toDateString() : now()->toDateString());

        return $this->postEntry(
            'order',
            (int) $order->id,
            $date,
            'Order #' . ($order->order_number ?: $order->id),
            'Sale completed' . ($order->customer_id ? ' (customer #' . $order->customer_id . ')' : ' (walk-in)'),
            $order->user_id,
            $lines,
        );
    }

    /** Expense cash_out: Dr Expenses, Cr Cash. cash_in is NOT posted. */
    public function postExpense(Expense $expense): ?JournalEntry
    {
        if ($expense->type !== 'cash_out') {
            return null;
        }
        $amount = round((float) $expense->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->postEntry(
            'expense',
            (int) $expense->id,
            $expense->expense_date ? $expense->expense_date->toDateString() : now()->toDateString(),
            'Expense #' . $expense->id,
            trim(($expense->category ? $expense->category . ' — ' : '') . ($expense->note ?? '')) ?: 'Expense',
            $expense->user_id,
            [
                ['account' => 'Expenses', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Cash', 'debit' => 0, 'credit' => $amount],
            ],
        );
    }

    /** PO received: Dr Inventory, Cr Supplier Payables (PO total). */
    public function postPurchaseOrder(PurchaseOrder $purchaseOrder): ?JournalEntry
    {
        $total = round((float) $purchaseOrder->total, 2);
        if ($total <= 0) {
            return null;
        }

        return $this->postEntry(
            'purchase_order',
            (int) $purchaseOrder->id,
            $purchaseOrder->updated_at ? $purchaseOrder->updated_at->toDateString() : now()->toDateString(),
            'PO #' . $purchaseOrder->id,
            'Purchase order received' . ($purchaseOrder->supplier_id ? ' (supplier #' . $purchaseOrder->supplier_id . ')' : ''),
            auth()->id(),
            [
                ['account' => 'Inventory', 'debit' => $total, 'credit' => 0],
                ['account' => 'Supplier Payables', 'debit' => 0, 'credit' => $total],
            ],
        );
    }

    /**
     * Supplier payment: Dr Supplier Payables, Cr Cash.
     * Types credit/debit are NOT auto-posted (see class docblock).
     */
    public function postSupplierPayment(SupplierPayment $payment): ?JournalEntry
    {
        if ($payment->type !== 'payment') {
            return null;
        }
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->postEntry(
            'supplier_payment',
            (int) $payment->id,
            $payment->created_at ? $payment->created_at->toDateString() : now()->toDateString(),
            'Supplier Payment #' . $payment->id,
            trim('Payment to supplier #' . $payment->supplier_id . ($payment->note ? ' — ' . $payment->note : '')),
            $payment->user_id,
            [
                ['account' => 'Supplier Payables', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Cash', 'debit' => 0, 'credit' => $amount],
            ],
        );
    }

    /**
     * Customer payment: Dr Cash, Cr Receivables.
     * Only type=payment posts; credit/debit types are NOT auto-posted
     * (see class docblock and NOTES.txt — the base app only ever
     * creates credit/debit CustomerPayment rows).
     */
    public function postCustomerPayment(CustomerPayment $payment): ?JournalEntry
    {
        if ($payment->type !== 'payment') {
            return null;
        }
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->postEntry(
            'customer_payment',
            (int) $payment->id,
            $payment->created_at ? $payment->created_at->toDateString() : now()->toDateString(),
            'Customer Payment #' . $payment->id,
            trim('Payment from customer #' . $payment->customer_id . ($payment->note ? ' — ' . $payment->note : '')),
            $payment->user_id,
            [
                ['account' => 'Cash', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Receivables', 'debit' => 0, 'credit' => $amount],
            ],
        );
    }

    /**
     * Cash borrow: Dr Cash, Cr Cash Borrowing.
     * Cash repay:  Dr Cash Borrowing, Cr Cash.
     */
    public function postCashTransaction(CashTransaction $transaction): ?JournalEntry
    {
        if (!in_array($transaction->type, ['borrow', 'repay'], true)) {
            return null;
        }
        $amount = round((float) $transaction->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $party = $transaction->party ? $transaction->party->name : ('party #' . $transaction->cash_party_id);
        $lines = $transaction->type === 'borrow'
            ? [
                ['account' => 'Cash', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Cash Borrowing', 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account' => 'Cash Borrowing', 'debit' => $amount, 'credit' => 0],
                ['account' => 'Cash', 'debit' => 0, 'credit' => $amount],
            ];

        return $this->postEntry(
            'cash_transaction',
            (int) $transaction->id,
            $transaction->transaction_date ? $transaction->transaction_date->toDateString() : now()->toDateString(),
            'Cash Transaction #' . $transaction->id,
            ($transaction->type === 'borrow' ? 'Borrowed from ' : 'Repayment to ') . $party
                . ($transaction->note ? ' — ' . $transaction->note : ''),
            $transaction->user_id,
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
                continue; // defensive: ignore any account outside the chart
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
