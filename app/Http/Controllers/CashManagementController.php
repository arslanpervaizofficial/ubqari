<?php

namespace App\Http\Controllers;

use App\Models\CashParty;
use App\Models\CashTransaction;
use App\Models\Expense;
use Illuminate\Http\Request;

/** Deliberately standalone: nothing in here touches products, stock,
 *  orders, or any customer/supplier balance. It's a personal ledger for
 *  tracking cash borrowed from (and repaid to) people/parties outside the
 *  POS's own business logic — plus, now, the single place where capital
 *  (Cash In) and liabilities are both recorded, so the Capital Report page
 *  can stay a pure read-only report instead of doubling as a data-entry
 *  form. */
class CashManagementController extends Controller
{
    public function index()
    {
        $parties = CashParty::orderByDesc('balance')->orderBy('name')->get();
        $totalOwed = $parties->sum('balance');
        // Shown here for context next to "+ Add Capital" — same figure the
        // Capital Report's Total/Remaining Investment cards are built from.
        $cashInjected = (float) Expense::where('type', 'cash_in')->sum('amount');
        return view('cash_management.index', compact('parties', 'totalOwed', 'cashInjected'));
    }

    public function storeParty(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $party = CashParty::create($data);
        return redirect()->route('cash-management.show', $party)->with('status', "{$party->name} added.");
    }

    /** One-shot "Add Liability" action used directly from this page (no
     *  need to first add a party, then separately open their ledger just
     *  to record the borrow). Picks an existing party by id, or creates a
     *  brand new one from party_name, then records a borrow transaction
     *  against it — same effect as using a party's own ledger page. */
    public function quickAddLiability(Request $request)
    {
        $data = $request->validate([
            'party_id' => ['nullable', 'exists:cash_parties,id'],
            'party_name' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($data['party_id']) && empty($data['party_name'])) {
            return back()->withErrors(['party' => 'Pick an existing person or enter a name for a new one.'])->withInput();
        }

        $party = !empty($data['party_id'])
            ? CashParty::findOrFail($data['party_id'])
            : CashParty::create(['name' => $data['party_name']]);

        $party->transactions()->create([
            'type' => 'borrow',
            'amount' => $data['amount'],
            'transaction_date' => $data['transaction_date'],
            'note' => $data['note'] ?? null,
            'user_id' => auth()->id(),
        ]);
        $party->increment('balance', $data['amount']);

        return back()->with('status', "Liability of {$data['amount']} added for {$party->name}.");
    }

    public function destroyParty(CashParty $cashParty)
    {
        $cashParty->delete(); // cascades to its transactions (see migration)
        return redirect()->route('cash-management.index')->with('status', "{$cashParty->name} and their transaction history removed.");
    }

    public function show(CashParty $cashParty)
    {
        $transactions = $cashParty->transactions()->with('user')->paginate(20);
        return view('cash_management.ledger', ['party' => $cashParty, 'transactions' => $transactions]);
    }

    /** borrow = you owe them more, balance goes up.
     *  repay  = you owe them less, balance goes down (capped at what's
     *  actually still owed, same protective check as the customer ledger's
     *  credit payments). */
    public function storeTransaction(Request $request, CashParty $cashParty)
    {
        $data = $request->validate([
            'type' => ['required', 'in:borrow,repay'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['type'] === 'repay' && $data['amount'] > $cashParty->balance) {
            return back()->withErrors(['amount' => "Amount ({$data['amount']}) is more than what's currently owed to {$cashParty->name} ({$cashParty->balance})."]);
        }

        $cashParty->transactions()->create($data + ['user_id' => auth()->id()]);

        if ($data['type'] === 'borrow') {
            $cashParty->increment('balance', $data['amount']);
        } else {
            $cashParty->decrement('balance', $data['amount']);
        }

        $label = $data['type'] === 'borrow' ? 'Borrowed amount' : 'Repayment';
        return back()->with('status', "{$label} of {$data['amount']} recorded for {$cashParty->name}.");
    }

    public function destroyTransaction(CashParty $cashParty, CashTransaction $transaction)
    {
        abort_unless($transaction->cash_party_id === $cashParty->id, 404);

        // Reverse its effect on the running balance before removing it —
        // otherwise the cached balance would silently drift from the sum
        // of what's actually in the history.
        if ($transaction->type === 'borrow') {
            $cashParty->decrement('balance', $transaction->amount);
        } else {
            $cashParty->increment('balance', $transaction->amount);
        }

        $transaction->delete();
        return back()->with('status', 'Transaction removed and balance adjusted.');
    }
}
