<?php

namespace App\Http\Controllers;

use App\Models\CashParty;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /** Three headline numbers. Deliberately an all-time snapshot ("how
     *  much capital is in this business right now"), not scoped to the
     *  date filter below — that filter only controls which past entries
     *  show in the table, since browsing history is a different question
     *  from "what's my current standing".
     *
     *  - Total Investment = current Stock Value PLUS all-time Cash In
     *    amounts PLUS Total Receivables — the same figure as "Total
     *    Investment" on the Capital Report (v1.3 formula, v1.4 applied
     *    here for consistency).
     *    Stock bought with the owner's money is just as much "invested" as
     *    cash sitting ready to spend.
     *  - Total Expenses = actual money spent (Cash Out only). Cash In is a
     *    capital injection, not an expense, so it's deliberately excluded
     *    here — it used to be included, which inflated this figure with
     *    money that was never actually spent.
     *  - Net Investment Remaining = Investment − Expenses − Liabilities:
     *    the same figure as "Net Capital" on the Capital Report. */
    public function index(Request $request)
    {
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();

        // Stock Value must use the NET cost per unit (purchase_price after
        // purchase_discount_percent) — see Product::net_purchase_price —
        // since purchase_price alone is the GROSS supplier rate.
        $stockValue = (float) Product::query()->get()->sum(fn ($p) => $p->stock * $p->net_purchase_price);
        $cashInjected = (float) Expense::where('type', 'cash_in')->sum('amount');
        // FIXED 2026-10-29 (v1.4) to match the Capital Report's v1.3
        // formula: Total Investment is current stock + injected cash +
        // receivables, and Net Remaining is that figure minus expenses
        // AND liabilities. The old version here omitted receivables and
        // liabilities entirely, so this page's Net Remaining was lower
        // than the Capital Report's Net Capital by exactly
        // (Receivables - Liabilities) for no good reason.
        // FIXED 2026-10-29 (v1.5): match the Capital Report — Receivables
        // includes walk-in order dues as well as named-customer credit
        // balances, otherwise this page again drifts from Capital Net.
        $totalReceivables = (float) Customer::sum('credit_balance')
            + (float) \App\Models\Order::whereNull('customer_id')->where('status', 'completed')->sum('due_amount');
        // v2.2, audit H15 completed — same change as ReportController::
        // capital(), kept identical so the two pages cannot drift apart
        // again: Liabilities = Cash Management borrowing + supplier dues
        // (positive suppliers.balance only; a negative balance is an
        // advance to a supplier, not a liability).
        $supplierLiabilities = (float) Supplier::where('balance', '>', 0)->sum('balance');
        $totalLiabilities = (float) CashParty::sum('balance') + $supplierLiabilities;
        $totalInvestment = $stockValue + $cashInjected + $totalReceivables;
        // Cash Out only — Cash In is capital, not an expense (see above).
        $totalExpenses = (float) Expense::where('type', 'cash_out')->sum('amount');
        $netRemaining = $totalInvestment - $totalExpenses - $totalLiabilities;

        $expenses = Expense::whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)
            ->with('user')->latest('expense_date')->latest('id')->paginate(20)->withQueryString();

        return view('expenses.index', compact(
            'expenses', 'totalInvestment', 'totalExpenses', 'netRemaining', 'stockValue', 'cashInjected', 'totalReceivables', 'totalLiabilities', 'supplierLiabilities', 'from', 'to'
        ));
    }

    /** Records an actual expense (Cash Out). Cash In (capital injection)
     *  has ONE entry point only: Cash Management's "+ Add Capital" form,
     *  which posts to this same endpoint with type=cash_in AND a hidden
     *  source=cash_management marker (see
     *  cash_management/cash_management_index.blade.php). Any cash_in
     *  submission without that marker — i.e. from the Expenses form, or
     *  forged directly — is rejected here, even though the Expenses form
     *  no longer offers a Cash In option at all. This keeps the
     *  legitimate Cash Management flow working while closing the hole
     *  that let a manager inflate Total Investment from the Expenses
     *  page. v2.2 (R7): the v2.0 rule that Cash Out entries must carry
     *  both a category and a note is REVERTED — a quick cash-out with
     *  just an amount and a date must save again, as it did originally;
     *  a missing category now defaults to "General" server-side. No
     *  entry — either type — may be dated in the future (v2.2, R8:
     *  judged in shop time, Asia/Karachi, not server time). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:cash_in,cash_out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            // v2.2 (R7): category and note are optional again, as in
            // the original — see the docblock above.
            'category' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            // v2.2 (R8): "today" is shop time (Asia/Karachi), so an
            // expense entered at 01:00–05:00 PKT is not rejected as
            // "future" when the server runs on UTC.
            'expense_date' => ['required', 'date', 'before_or_equal:' . \Carbon\Carbon::now('Asia/Karachi')->toDateTimeString()],
        ]);

        // v2.2 (R7): default a missing category server-side so
        // category-less cash-outs still group under something.
        if (empty($data['category'])) {
            $data['category'] = 'General';
        }

        if ($data['type'] === 'cash_in' && $request->input('source') !== 'cash_management') {
            return back()->withErrors(['type' => 'Cash In can only be recorded from the Cash Management page (Add Capital), not from Expenses.'])->withInput();
        }

        Expense::create($data + ['user_id' => auth()->id()]);

        $label = $data['type'] === 'cash_in' ? 'Cash-in expense' : 'Cash-out expense';
        return back()->with('status', "{$label} of {$data['amount']} recorded.");
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return back()->with('status', 'Expense moved to Trash.');
    }

    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $count = Expense::whereIn('id', $ids)->count();
        Expense::whereIn('id', $ids)->delete();
        return back()->with('status', "{$count} expense(s) moved to Trash.");
    }
}
