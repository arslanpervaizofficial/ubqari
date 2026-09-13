<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Product;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /** Three headline numbers. Deliberately an all-time snapshot ("how
     *  much capital is in this business right now"), not scoped to the
     *  date filter below — that filter only controls which past entries
     *  show in the table, since browsing history is a different question
     *  from "what's my current standing".
     *
     *  - Total Investment = current Stock Value (everything sitting in
     *    inventory, at purchase price — money already tied up in goods)
     *    PLUS all-time Cash In amounts (capital injected via Cash
     *    Management's "+ Add Capital" — see CashManagementController).
     *    Stock bought with the owner's money is just as much "invested" as
     *    cash sitting ready to spend.
     *  - Total Expenses = actual money spent (Cash Out only). Cash In is a
     *    capital injection, not an expense, so it's deliberately excluded
     *    here — it used to be included, which inflated this figure with
     *    money that was never actually spent.
     *  - Net Investment Remaining = Investment − Expenses: how much of the
     *    invested capital is still sitting unspent/in stock. */
    public function index(Request $request)
    {
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();

        // Stock Value must use the NET cost per unit (purchase_price after
        // purchase_discount_percent) — see Product::net_purchase_price —
        // since purchase_price alone is the GROSS supplier rate.
        $stockValue = (float) Product::query()->get()->sum(fn ($p) => $p->stock * $p->net_purchase_price);
        $cashInjected = (float) Expense::where('type', 'cash_in')->sum('amount');
        $totalInvestment = $stockValue + $cashInjected;
        // Cash Out only — Cash In is capital, not an expense (see above).
        $totalExpenses = (float) Expense::where('type', 'cash_out')->sum('amount');
        $netRemaining = $totalInvestment - $totalExpenses;

        $expenses = Expense::whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)
            ->with('user')->latest('expense_date')->latest('id')->paginate(20)->withQueryString();

        return view('expenses.index', compact(
            'expenses', 'totalInvestment', 'totalExpenses', 'netRemaining', 'stockValue', 'cashInjected', 'from', 'to'
        ));
    }

    /** Records an actual expense (Cash Out). Cash In (capital injection) no
     *  longer has a form here — it's recorded exclusively via Cash
     *  Management's "+ Add Capital" (see CashManagementController), which
     *  posts to this same endpoint with type=cash_in under the hood, so
     *  this validation still has to accept it. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:cash_in,cash_out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
        ]);

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
