<?php

namespace App\Http\Controllers;

use App\Models\CashParty;
use App\Models\CashTransaction;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // Party create + borrow row + balance must succeed or fail
        // together, and the balance is recomputed from the history
        // (syncBalance) rather than trusted to a manual increment, so a
        // half-applied write can never leave them disagreeing.
        $party = DB::transaction(function () use ($data) {
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
            $party->syncBalance();
            return $party;
        });

        return back()->with('status', "Liability of {$data['amount']} added for {$party->name}.");
    }

    /** Soft delete only (CashParty uses SoftDeletes). The old version
     *  hard-deleted the party and the database cascade hard-deleted
     *  every transaction with it — an irreversible loss of borrowing
     *  history and an instant, silent drop in Capital Liabilities. A
     *  soft delete leaves the transactions in place as history.
     *  NOTE: Trash must list cash parties before a trashed party can be
     *  restored from the UI (see CHANGES.txt); until then recovery is
     *  via the database (restore the deleted_at value). */
    public function destroyParty(CashParty $cashParty)
    {
        $cashParty->delete();
        return redirect()->route('cash-management.index')->with('status', "{$cashParty->name} moved to Trash. Their transaction history is kept.");
    }

    public function show(CashParty $cashParty)
    {
        // Repair any drift before showing: if the cached balance ever
        // disagreed with the transaction history, this is the page that
        // fixes it (same idea as the customer ledger's syncBalance()).
        // v2.2 (R6): a correction is no longer silent — the old and new
        // figures are passed to the view so the page says so.
        $balanceBefore = (float) $cashParty->balance;
        $cashParty->syncBalance();
        $cashParty->refresh();
        $balanceCorrected = abs((float) $cashParty->balance - $balanceBefore) > 0.004
            ? ['old' => $balanceBefore, 'new' => (float) $cashParty->balance]
            : null;
        // v2.2 (F8): stable chronological order — pagination without
        // an order can repeat or skip rows across pages.
        $transactions = $cashParty->transactions()->with('user')->latest('transaction_date')->latest('id')->paginate(20);
        return view('cash_management.ledger', ['party' => $cashParty, 'transactions' => $transactions, 'balanceCorrected' => $balanceCorrected]);
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
            // v2.2 (F8/R8): future dates rejected, judged in shop time
            // (Asia/Karachi), not server time.
            'transaction_date' => ['required', 'date', 'before_or_equal:' . \Carbon\Carbon::now('Asia/Karachi')->toDateTimeString()],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // Transaction row + balance recompute inside one transaction,
        // with the repay cap re-checked against a row-locked fresh
        // balance — the old code checked the (possibly stale) bound
        // balance and then updated it in two separate queries, so two
        // cashiers acting at once could over-repay or leave the balance
        // disagreeing with the history.
        DB::transaction(function () use ($cashParty, $data) {
            $party = CashParty::whereKey($cashParty->id)->lockForUpdate()->firstOrFail();
            if ($data['type'] === 'repay' && $data['amount'] > (float) $party->balance) {
                throw ValidationException::withMessages(['amount' => "Amount ({$data['amount']}) is more than what's currently owed to {$party->name} ({$party->balance})."]);
            }
            $party->transactions()->create($data + ['user_id' => auth()->id()]);
            $party->syncBalance();
        });

        $label = $data['type'] === 'borrow' ? 'Borrowed amount' : 'Repayment';
        return back()->with('status', "{$label} of {$data['amount']} recorded for {$cashParty->name}.");
    }

    public function destroyTransaction(CashParty $cashParty, CashTransaction $transaction)
    {
        abort_unless($transaction->cash_party_id === $cashParty->id, 404);

        // Removing a borrow lowers the balance; if that would take it
        // below zero (e.g. the borrow was already repaid), refuse rather
        // than recording a negative amount owed. Removing a repay only
        // ever raises the balance, so it needs no such guard.
        if ($transaction->type === 'borrow' && (float) $transaction->amount > (float) $cashParty->balance) {
            return back()->withErrors(['amount' => "Removing this borrow of {$transaction->amount} would take {$cashParty->name}'s balance below zero (currently owed: {$cashParty->balance}). Record the position correctly first — e.g. the repayments against it — then remove it."]);
        }

        // Delete (now a soft delete via the model's SoftDeletes trait) +
        // recompute the balance from the remaining live transactions, so
        // the cached balance can never drift from the history.
        DB::transaction(function () use ($cashParty, $transaction) {
            $transaction->delete();
            $cashParty->syncBalance();
        });
        return back()->with('status', 'Transaction removed and balance adjusted.');
    }
}
