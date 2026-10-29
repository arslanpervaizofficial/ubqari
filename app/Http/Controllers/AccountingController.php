<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Http\Request;

/**
 * Ubqari POS v3.0 — Feature 6 (Full Accounting).
 *
 * All figures here come from journal_entries / journal_lines ONLY —
 * never from orders, expenses or any operational table. That is the
 * point: P&L and Balance Sheet are views over the double-entry
 * journal, so they reconcile with the journal list by construction.
 *
 * Gates mirror the app's route pattern (role:admin,manager for
 * viewing, role:admin for the opening-balance form) and are enforced
 * here as well as in routes, so the pages stay protected even if the
 * routes are merged into a different group.
 *
 * NO BACKFILL: figures cover journal entries from install date
 * onward only. Business done before this feature was installed has
 * no journal entries and is invisible here — use the opening-balance
 * form (admin) to bring opening Cash/Inventory/Capital into the
 * journal instead of pretending history exists.
 */
class AccountingController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    private function requireManager(): void
    {
        abort_unless(auth()->check() && in_array(auth()->user()->role, ['admin', 'manager'], true), 403);
    }

    private function requireAdmin(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }

    /** Journal list: paginated, filterable by account and entry date. */
    public function index(Request $request)
    {
        $this->requireManager();

        $account = $request->input('account');
        $from = $request->input('from');
        $to = $request->input('to');

        $entries = JournalEntry::with('lines', 'user')
            ->when($account && in_array($account, AccountingService::ACCOUNTS, true), function ($query) use ($account) {
                $query->whereHas('lines', fn ($q) => $q->where('account', $account));
            })
            ->when($from, fn ($query) => $query->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entry_date', '<=', $to))
            ->latest('entry_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('accounting.journal', [
            'entries' => $entries,
            'accounts' => AccountingService::ACCOUNTS,
            'account' => $account,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** Profit & Loss, from the journal only, over an optional range. */
    public function profitLoss(Request $request)
    {
        $this->requireManager();

        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();

        $totals = $this->accounting->accountTotals($from, $to);

        // Revenue is a net credit; Sales Returns, COGS and Expenses
        // are net debits. v3.1.0 (M11): Sales Returns (customer-return
        // credits) is contra-revenue, so it is subtracted from revenue
        // — without this, returns would overstate profit and the
        // Balance Sheet retained figure would not reconcile.
        $revenue = round($totals['Sales Revenue']['credit'] - $totals['Sales Revenue']['debit'], 2);
        $salesReturns = round($totals['Sales Returns']['debit'] - $totals['Sales Returns']['credit'], 2);
        $cogs = round($totals['COGS']['debit'] - $totals['COGS']['credit'], 2);
        $expenses = round($totals['Expenses']['debit'] - $totals['Expenses']['credit'], 2);
        $net = round($revenue - $salesReturns - $cogs - $expenses, 2);

        return view('accounting.profit_loss', compact(
            'from', 'to', 'revenue', 'salesReturns', 'cogs', 'expenses', 'net'
        ));
    }

    /**
     * Balance Sheet, from the journal only.
     * Optional as_of date (entries on/before that date); default all-time.
     * Equity = Capital (net credit) + retained P&L net over the same
     * scope, so Assets should equal Liabilities + Equity whenever
     * every entry balances — the difference is shown, not hidden.
     */
    public function balanceSheet(Request $request)
    {
        $this->requireManager();

        $asOf = $request->input('as_of') ?: null;
        $totals = $this->accounting->accountTotals(null, $asOf);

        $netDebit = fn (string $account) => round($totals[$account]['debit'] - $totals[$account]['credit'], 2);
        $netCredit = fn (string $account) => round($totals[$account]['credit'] - $totals[$account]['debit'], 2);

        $assets = [
            'Cash' => $netDebit('Cash'),
            'Bank' => $netDebit('Bank'),
            'Inventory' => $netDebit('Inventory'),
            'Receivables' => $netDebit('Receivables'),
        ];
        $totalAssets = round(array_sum($assets), 2);

        $liabilities = [
            'Supplier Payables' => $netCredit('Supplier Payables'),
            'Cash Borrowing' => $netCredit('Cash Borrowing'),
        ];
        $totalLiabilities = round(array_sum($liabilities), 2);

        $capital = $netCredit('Capital');
        // v3.1.0 (M11): retained profit nets off Sales Returns, exactly
        // like profitLoss() above, so the Balance Sheet still balances
        // (difference 0) when return credits have been posted.
        $retained = round(
            $netCredit('Sales Revenue') - $netDebit('Sales Returns') - $netDebit('COGS') - $netDebit('Expenses'),
            2
        );
        $totalEquity = round($capital + $retained, 2);
        $totalLiabilitiesEquity = round($totalLiabilities + $totalEquity, 2);
        $difference = round($totalAssets - $totalLiabilitiesEquity, 2);

        return view('accounting.balance_sheet', [
            'asOf' => $asOf,
            'assets' => $assets,
            'totalAssets' => $totalAssets,
            'liabilities' => $liabilities,
            'totalLiabilities' => $totalLiabilities,
            'capital' => $capital,
            'retained' => $retained,
            'totalEquity' => $totalEquity,
            'totalLiabilitiesEquity' => $totalLiabilitiesEquity,
            'difference' => $difference,
        ]);
    }

    /** Manual opening-balance form (admin only). */
    public function openingBalance()
    {
        $this->requireAdmin();

        return view('accounting.opening_balance', [
            'accounts' => AccountingService::ACCOUNTS,
        ]);
    }

    /** Creates ONE balanced manual entry (admin only). */
    public function storeOpeningBalance(Request $request)
    {
        $this->requireAdmin();

        // Normalise blank form rows (empty account string -> null) so
        // the nullable validation below holds even if the app's
        // ConvertEmptyStringsToNull middleware is ever removed.
        $request->merge(['lines' => array_map(function ($row) {
            if (is_array($row) && empty($row['account'])) {
                $row['account'] = null;
            }

            return $row;
        }, (array) $request->input('lines', []))]);

        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array'],
            'lines.*.account' => ['nullable', 'string', 'in:' . implode(',', AccountingService::ACCOUNTS)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Drop empty rows, then enforce the journal rules in plain
        // language before the service re-enforces them by throwing.
        // (Rows are nullable above on purpose: the form always submits
        // all six rows, most of them blank.)
        $lines = [];
        foreach ($data['lines'] as $row) {
            $debit = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);
            if (empty($row['account'])) {
                if ($debit > 0 || $credit > 0) {
                    return back()->withInput()->withErrors([
                        'lines' => 'A line with an amount must also have an account chosen.',
                    ]);
                }
                continue;
            }
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }
            if ($debit > 0 && $credit > 0) {
                return back()->withInput()->withErrors([
                    'lines' => 'Each line must be either a debit OR a credit, not both.',
                ]);
            }
            $lines[] = ['account' => $row['account'], 'debit' => $debit, 'credit' => $credit];
        }

        if (count($lines) < 2) {
            return back()->withInput()->withErrors([
                'lines' => 'Enter at least two lines (at least one debit and one credit).',
            ]);
        }

        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);
        if ($totalDebit <= 0 || $totalCredit <= 0 || abs($totalDebit - $totalCredit) > 0.004) {
            return back()->withInput()->withErrors([
                'lines' => "The entry does not balance: debits {$totalDebit} vs credits {$totalCredit}. Adjust the lines so both sides match.",
            ]);
        }

        $this->accounting->postOpeningBalance(
            $data['entry_date'],
            $data['description'] ?? null,
            auth()->id(),
            $lines,
        );

        return redirect()->route('accounting.journal')
            ->with('status', 'Opening-balance journal entry posted.');
    }
}
