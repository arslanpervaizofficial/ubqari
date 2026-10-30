<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * v3.2.5 — one-time (and repeatable-safe) repair for stored customer
 * balances.
 *
 * The problem: the customers list, the billing previous balance and
 * Capital used to read the STORED customers.credit_balance column,
 * which is maintained by many incremental writers and therefore
 * drifts from the ledger recomputation (Customer::ledgerFigures()):
 * desktop-pushed sales never incremented it, order-level debits are
 * erased by the next ledger visit, and any missed writer left
 * permanent drift. Since v3.2.5 every displayed balance IS the
 * recomputation, and the stored column is only a reconciled cache.
 * This command brings that cache back in line for every live
 * customer, once, after the v3.2.5 files are installed.
 *
 * NO ARGUMENTS BY DESIGN: this host's cron drops arguments after
 * `artisan`, and the key-gated bootstrap trigger route calls
 * Artisan::call() with a fixed command name — a no-argument command
 * is the only form that runs reliably through both routes.
 *
 * IDEMPOTENT: it calls the existing syncBalance() per customer,
 * which recomputes from source rows and only writes when drift
 * exceeds 0.004. Re-running changes nothing (every line prints OK).
 * It uses NO new incremental writer, so replay can never
 * double-count. Trashed customers are skipped: they are invisible
 * to the list and to Capital sums (default scope), so their stored
 * value is irrelevant.
 *
 * KNOWN EFFECT (same as opening every ledger page once): for a
 * customer with an outstanding order-level debit, the stored column
 * is overwritten with the recomputation, i.e. the debit drops out of
 * the stored figure. That is the pre-existing syncBalance()
 * behaviour, not a new rule — and the debit itself is untouched:
 * it stays in orders.due_amount, on the order ledger, and in Aging
 * (which is due_amount-based). The formula itself is unchanged.
 *
 * Output: one short line per customer (CHANGED with before -> after,
 * or OK), then a summary line. Run it once after installing v3.2.5,
 * verify the summary, then remove the cron/trigger (see
 * README-INSTALL.md). Afterwards the sync-push reconciliation keeps
 * the cache current on every push, so this command should not be
 * needed again unless drift is suspected.
 */
class ReconcileCustomerBalances extends Command
{
    protected $signature = 'customers:reconcile-balances';

    protected $description = 'Recompute every live customer\'s stored credit_balance from their ledger (orders + payments) and report before/after per customer.';

    public function handle(): int
    {
        $changed = 0;
        $total = 0;
        $totalDrift = 0.0;

        Customer::orderBy('name')->chunkById(100, function ($customers) use (&$changed, &$total, &$totalDrift) {
            foreach ($customers as $customer) {
                $total++;
                $before = (float) $customer->credit_balance;
                $figures = $customer->syncBalance();
                $after = (float) $figures['balance'];
                if (abs($before - $after) > 0.004) {
                    $changed++;
                    $totalDrift += abs($before - $after);
                    $this->line(sprintf('CHANGED  #%d %s: %s -> %s', $customer->id, $customer->name, number_format($before, 2), number_format($after, 2)));
                } else {
                    $this->line(sprintf('OK       #%d %s: %s', $customer->id, $customer->name, number_format($after, 2)));
                }
            }
        });

        $this->info(sprintf('Done: %d customers checked, %d changed, total absolute drift Rs %s.', $total, $changed, number_format($totalDrift, 2)));

        return self::SUCCESS;
    }
}
