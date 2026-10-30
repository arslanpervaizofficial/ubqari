<?php

namespace App\Http\Controllers;

use App\Models\CashParty;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SyncFlag;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Server side of SYNC_API_CONTRACT.md: POST /api/sync/push,
 *  GET /api/sync/pull, GET /api/sync/ping. All routes sit behind the
 *  sync.token middleware (device token, not a user session).
 *
 *  Deliberate rules, all documented here because the coordinator and
 *  the desktop worker both depend on them:
 *
 *  - Field whitelists below are taken from the REAL server columns
 *    (base migrations + models). Request data is never mass-assigned:
 *    only whitelisted keys are read, everything else is ignored, and
 *    writes go through forceFill() — which matters because v3.1.0
 *    deliberately removed money/status fields (orders.total,
 *    paid_amount, status, ...) from $fillable, so a plain create()
 *    would silently drop them. forceFill with an explicit whitelist
 *    is the sync-only path around that guard, not a hole in it.
 *  - `stock` is NEVER accepted from push data (it is not in the
 *    products whitelist at all): stock is event-sourced. A pushed
 *    movement adjusts products.stock by its signed quantity; a
 *    pushed product row carrying `stock` has that key ignored.
 *  - Ledger-cached balances are never synced either: customers'
 *    credit_balance, suppliers' balance and cash_parties' balance
 *    are all derived on the server (syncBalance() / ledger figures),
 *    so they are absent from both push and pull whitelists. The
 *    desktop recomputes them from ledgers, per DESIGN.md §2.
 *  - `image` (customers, suppliers) is excluded: file/image sync is
 *    out of the contract; the desktop shows a placeholder.
 *  - `user_id` is excluded everywhere: it points at server-only
 *    users, and a desktop client has no server user identity. Pushed
 *    orders therefore store a null cashier — which is why migration
 *    2026_10_30_000004 makes orders.user_id nullable.
 *  - Conflict rule is UNIFORM (task binding, and a deviation from
 *    DESIGN.md §5's last-write-wins for master data): a stale push
 *    (server updated_at newer than the incoming one) is rejected
 *    with conflict_server_wins for EVERY table, money and master
 *    data alike. The client pulls and flags needs_review; it must
 *    not retry blindly.
 *  - stock_movements are insert-only: an existing movement with the
 *    same client_uuid and an identical payload is an idempotent ACK;
 *    the same client_uuid with a different payload is rejected with
 *    duplicate_movement. A movement is never updated.
 *  - Deletes: soft-delete where the model uses SoftDeletes, hard
 *    delete otherwise. Hard deletes are invisible to pull (there is
 *    no tombstone row left to report) — documented, not fixed, since
 *    adding tombstones to non-soft tables is out of scope.
 *  - Writes use normal save()/delete(), NOT saveQuietly(): the
 *    accounting observers (OrderObserver, PurchaseOrderObserver,
 *    payment/expense observers) must see sync writes exactly as
 *    they see web writes. KNOWN GAP, flagged for the coordinator:
 *    those observers post journals on status TRANSITIONS (an order
 *    BECOMING completed). A desktop sale pushed straight in as
 *    'completed' is a create, not a transition, so no sale journal
 *    is posted for it — it lands on the journal page's unposted
 *    list. Sync deliberately does NOT call AccountingService
 *    directly: inventing journal entries here risks double-posting
 *    against the observers' own guards. The coordinator needs a
 *    decision (two-step create, or a manual post step) before
 *    desktop sales are treated as fully journalled.
 *  - Product batches are NOT adjusted by sync: a pushed movement
 *    changes products.stock only. Batch remaining quantities are a
 *    server-side breakdown maintained by the web sale/receive paths;
 *    sync does not run FIFO consume/mirror helpers for pushed
 *    movements. Batches pushed by the desktop are stored as rows.
 */
class SyncController extends Controller
{
    private const PULL_LIMIT_DEFAULT = 100;
    private const PULL_LIMIT_MAX = 500;
    private const PUSH_MAX_CHANGES = 1000;
    private const FLAG_LIMIT = 200;

    /** Date-only columns: pulled as YYYY-MM-DD, never datetimes. */
    private const DATE_ONLY_FIELDS = ['expiry_date', 'expense_date', 'transaction_date', 'count_date'];

    /** Datetime columns beyond updated_at: pulled as UTC ISO-8601. */
    private const DATETIME_FIELDS = ['received_at', 'original_completed_at'];

    /**
     * The sync registry. Key order is the pull ordering tiebreak
     * (after updated_at): Phase 1 tables first, then Phase 2.
     *
     *  - model: Eloquent model for the table.
     *  - fields: scalar columns the desktop may push AND pull.
     *  - pull_only: columns pulled but never accepted on push
     *    (derived values the server owns).
     *  - pull_extra: extra pulled columns not pushable at all
     *    (products.stock: server cached value, reported for the
     *    desktop to compare against its movement recomputation).
     *  - fks: wire key (a *_client_uuid) => [column, target table].
     *  - soft: whether the model uses SoftDeletes (decides
     *    soft-delete on push and withTrashed on pull).
     *  - insert_only: stock_movements only.
     */
    private function registry(): array
    {
        return [
            'products' => [
                'model' => Product::class,
                'fields' => ['name', 'sku', 'barcode', 'category', 'unit', 'purchase_price', 'purchase_discount_percent', 'sale_price', 'max_discount_percent', 'min_stock', 'max_stock', 'is_active'],
                'pull_extra' => ['stock'],
                'fks' => [],
                'soft' => false,
            ],
            'customers' => [
                'model' => Customer::class,
                'fields' => ['name', 'phone', 'email', 'address', 'id_card_number', 'current_address', 'permanent_address', 'discount_percent', 'is_active'],
                'fks' => [],
                'soft' => true,
            ],
            'suppliers' => [
                'model' => Supplier::class,
                'fields' => ['name', 'phone', 'email', 'address', 'id_card_number', 'current_address', 'permanent_address', 'is_active'],
                'fks' => [],
                'soft' => true,
            ],
            'purchase_orders' => [
                'model' => PurchaseOrder::class,
                'fields' => ['status', 'subtotal', 'discount_percent', 'discount_amount', 'total', 'received_at'],
                'fks' => ['supplier_client_uuid' => ['supplier_id', 'suppliers']],
                'soft' => true,
            ],
            'purchase_order_items' => [
                'model' => PurchaseOrderItem::class,
                'fields' => ['quantity', 'received_quantity', 'cost_price', 'discount_percent'],
                'fks' => [
                    'purchase_order_client_uuid' => ['purchase_order_id', 'purchase_orders'],
                    'product_client_uuid' => ['product_id', 'products'],
                ],
                'soft' => false,
            ],
            'orders' => [
                'model' => Order::class,
                'fields' => ['order_number', 'status', 'subtotal', 'line_discount_total', 'discount_percent', 'discount_amount', 'total', 'paid_amount', 'due_amount', 'payment_method', 'bank_name', 'transaction_id', 'is_quotation', 'cash_amount', 'bank_amount', 'original_completed_at'],
                'fks' => ['customer_client_uuid' => ['customer_id', 'customers']],
                'soft' => true,
            ],
            'order_items' => [
                'model' => OrderItem::class,
                'fields' => ['quantity', 'unit_price', 'discount_percent', 'discount_amount', 'line_total'],
                'fks' => [
                    'order_client_uuid' => ['order_id', 'orders'],
                    'product_client_uuid' => ['product_id', 'products'],
                ],
                'soft' => false,
            ],
            'stock_movements' => [
                'model' => StockMovement::class,
                'fields' => ['type', 'quantity', 'reason'],
                'fks' => [
                    'product_client_uuid' => ['product_id', 'products'],
                    'order_client_uuid' => ['order_id', 'orders'],
                    'purchase_order_client_uuid' => ['purchase_order_id', 'purchase_orders'],
                    'stock_return_client_uuid' => ['stock_return_id', 'stock_returns'],
                ],
                'soft' => false,
                'insert_only' => true,
            ],
            'product_batches' => [
                'model' => ProductBatch::class,
                'fields' => ['batch_no', 'expiry_date', 'quantity', 'remaining_quantity'],
                'fks' => [
                    'product_client_uuid' => ['product_id', 'products'],
                    'purchase_order_client_uuid' => ['purchase_order_id', 'purchase_orders'],
                ],
                'soft' => false,
            ],
            'stock_returns' => [
                'model' => StockReturn::class,
                'fields' => ['type', 'quantity', 'unit_price', 'discount_percent', 'discount_amount', 'reason'],
                'fks' => [
                    'product_client_uuid' => ['product_id', 'products'],
                    'customer_client_uuid' => ['customer_id', 'customers'],
                    'supplier_client_uuid' => ['supplier_id', 'suppliers'],
                    'order_client_uuid' => ['order_id', 'orders'],
                    'order_item_client_uuid' => ['order_item_id', 'order_items'],
                    'purchase_order_client_uuid' => ['purchase_order_id', 'purchase_orders'],
                    'purchase_order_item_client_uuid' => ['purchase_order_item_id', 'purchase_order_items'],
                ],
                'soft' => true,
            ],
            'expenses' => [
                'model' => Expense::class,
                'fields' => ['type', 'amount', 'category', 'note', 'expense_date'],
                'fks' => [],
                'soft' => true,
            ],
            'cash_parties' => [
                'model' => CashParty::class,
                'fields' => ['name', 'note'],
                'fks' => [],
                'soft' => true,
            ],
            'cash_transactions' => [
                'model' => CashTransaction::class,
                'fields' => ['type', 'amount', 'transaction_date', 'note'],
                'fks' => ['cash_party_client_uuid' => ['cash_party_id', 'cash_parties']],
                'soft' => true,
            ],
            'customer_payments' => [
                'model' => CustomerPayment::class,
                'fields' => ['type', 'amount', 'note'],
                'fks' => [
                    'customer_client_uuid' => ['customer_id', 'customers'],
                    'stock_return_client_uuid' => ['stock_return_id', 'stock_returns'],
                ],
                'soft' => true,
            ],
            'supplier_payments' => [
                'model' => SupplierPayment::class,
                'fields' => ['type', 'amount', 'note'],
                'fks' => ['supplier_client_uuid' => ['supplier_id', 'suppliers']],
                'soft' => true,
            ],
            'stock_counts' => [
                'model' => StockCount::class,
                'fields' => ['count_date', 'status', 'note'],
                'fks' => [],
                'soft' => false,
            ],
            'stock_count_items' => [
                'model' => StockCountItem::class,
                'fields' => ['snapshot_stock', 'counted_stock'],
                // variance is derived (counted - snapshot) by the
                // model's saving event: pulled for display, never
                // accepted on push.
                'pull_only' => ['variance'],
                'fks' => [
                    'stock_count_client_uuid' => ['stock_count_id', 'stock_counts'],
                    'product_client_uuid' => ['product_id', 'products'],
                ],
                'soft' => false,
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Endpoints
    // ----------------------------------------------------------------

    /** POST /api/sync/push — partial success is normal: 200 always,
     *  with per-row accepted/rejected lists. A row is never allowed
     *  to fail the whole batch (unknown tables included). */
    public function push(Request $request): JsonResponse
    {
        $changes = $request->input('changes');

        if (!is_array($changes)) {
            return $this->error('validation_failed', 'Request body must contain a "changes" array.', 422);
        }

        if (count($changes) > self::PUSH_MAX_CHANGES) {
            return $this->error('validation_failed', 'Batch too large: at most '.self::PUSH_MAX_CHANGES.' changes per request.', 422);
        }

        $registry = $this->registry();
        $accepted = [];
        $rejected = [];
        $touchedProductIds = [];

        // Two passes per batch: a child arriving before its parent
        // defers FK resolution to pass 2 instead of failing. Only
        // FK-unresolved rows defer; every other outcome is final in
        // pass 1.
        $deferred = [];
        foreach ($changes as $change) {
            $this->applyChange($change, $registry, $accepted, $rejected, $deferred, $touchedProductIds);
        }
        foreach ($deferred as $change) {
            $stillDeferred = [];
            $this->applyChange($change, $registry, $accepted, $rejected, $stillDeferred, $touchedProductIds);
            foreach ($stillDeferred as $leftover) {
                $rejected[] = [
                    'table' => $leftover['table'] ?? null,
                    'client_uuid' => $leftover['client_uuid'] ?? null,
                    'code' => 'unresolved_reference',
                    'message' => 'A referenced row could not be found on the server.',
                ];
            }
        }

        $this->flagNegativeStock($touchedProductIds);

        // v3.2.5: refresh the stored credit_balance of every customer
        // this batch touched, from the recomputation — otherwise a
        // desktop-pushed sale would leave the stored column lagging
        // until someone opened that customer's ledger page.
        $this->reconcileCustomerBalancesAfterPush($accepted, $changes);

        return response()->json(['accepted' => $accepted, 'rejected' => $rejected]);
    }

    /** v3.2.5 — sync-push balance reconciliation (see push()).
     *
     *  WHAT: for every customer affected by this batch's ACCEPTED
     *  rows, recompute the stored credit_balance from ledgerFigures()
     *  (via the bulk helper) and overwrite it when it drifted by more
     *  than 0.004. Rejected rows never applied, so they are excluded.
     *  Only three tables can move a customer balance: orders (a sale),
     *  customer_payments (a ledger entry), and from_customer
     *  stock_returns (their credit payment is itself a
     *  customer_payments row, but the return is collected too so a
     *  batch carrying the return without its payment still triggers
     *  the customer). order_items, movements, POs, expenses and cash
     *  never touch a customer balance and are ignored.
     *
     *  PLACEMENT: after both passes and flagNegativeStock, never
     *  inside applyChange. push() has no batch transaction (each row
     *  saves on its own), so "after the batch" means after every
     *  applied row is committed; the recompute therefore reads
     *  committed rows only.
     *
     *  RE-PUSH SAFETY (proof): recomputation is a pure function of
     *  (completed orders, customer_payments). Re-push is idempotent
     *  by construction — same client_uuid + updated_at is an ACK with
     *  no rewrite, and a newer server row rejects the push
     *  (server-wins) — so a re-pushed batch leaves identical source
     *  rows, the recompute yields the identical stored value (or no
     *  write, inside the 0.004 threshold). Re-running this method
     *  cannot change a correct balance, and no incremental writer is
     *  used anywhere, so replay can never double-count.
     *
     *  WRITE MECHANISM: a direct query update that does NOT touch
     *  updated_at. A save() would bump updated_at, which would make
     *  the customer row look newer and cause later desktop customer
     *  edits to be rejected as server-wins conflicts, plus pointless
     *  customer re-pulls. The value is a derived cache refresh, not a
     *  user action, so no audit row is owed either.
     *
     *  FAILURE MODE: any failure here is logged and NEVER fails the
     *  push response — a lagging cache self-heals on the next push,
     *  ledger visit, or reconcile-command run, while a failed push
     *  would block the user's sync entirely. */
    private function reconcileCustomerBalancesAfterPush(array $accepted, array $changes): void
    {
        try {
            $acceptedKeys = [];
            $orderIds = [];
            $paymentIds = [];
            $returnIds = [];
            foreach ($accepted as $entry) {
                $table = $entry['table'] ?? null;
                $clientUuid = $entry['client_uuid'] ?? null;
                $serverId = $entry['server_id'] ?? null;
                if (is_string($table) && is_string($clientUuid)) {
                    $acceptedKeys[$table . '|' . $clientUuid] = true;
                }
                if (!is_int($serverId) && !is_numeric($serverId)) {
                    continue;
                }
                if ($table === 'orders') {
                    $orderIds[] = (int) $serverId;
                } elseif ($table === 'customer_payments') {
                    $paymentIds[] = (int) $serverId;
                } elseif ($table === 'stock_returns') {
                    $returnIds[] = (int) $serverId;
                }
            }
            if ($acceptedKeys === []) {
                return;
            }

            $affected = [];
            if ($orderIds !== []) {
                foreach (Order::withTrashed()->whereIn('id', $orderIds)->pluck('customer_id') as $cid) {
                    if ($cid !== null) {
                        $affected[(int) $cid] = true;
                    }
                }
            }
            if ($paymentIds !== []) {
                foreach (CustomerPayment::withTrashed()->whereIn('id', $paymentIds)->pluck('customer_id') as $cid) {
                    if ($cid !== null) {
                        $affected[(int) $cid] = true;
                    }
                }
            }
            if ($returnIds !== []) {
                foreach (StockReturn::withTrashed()->whereIn('id', $returnIds)->where('type', 'from_customer')->pluck('customer_id') as $cid) {
                    if ($cid !== null) {
                        $affected[(int) $cid] = true;
                    }
                }
            }

            // An order update that moves a sale from one customer to
            // another leaves the OLD customer only in the incoming
            // change data (the row now points at the new customer), so
            // resolve customer_client_uuid from accepted changes' data
            // as well. (The desktop cannot do this today — it has no
            // order-customer edit — but the reconciliation must not
            // depend on that staying true.)
            $dataUuids = [];
            foreach ($changes as $change) {
                $table = $change['table'] ?? null;
                if (!in_array($table, ['orders', 'customer_payments', 'stock_returns'], true)) {
                    continue;
                }
                $clientUuid = $change['client_uuid'] ?? null;
                if (!is_string($clientUuid) || !isset($acceptedKeys[$table . '|' . $clientUuid])) {
                    continue;
                }
                $uuid = $change['data']['customer_client_uuid'] ?? null;
                if (is_string($uuid) && Str::isUuid($uuid)) {
                    $dataUuids[] = $uuid;
                }
            }
            if ($dataUuids !== []) {
                foreach (Customer::withTrashed()->whereIn('client_uuid', array_unique($dataUuids))->pluck('id') as $cid) {
                    $affected[(int) $cid] = true;
                }
            }

            $ids = array_keys($affected);
            if ($ids === []) {
                return;
            }

            $balances = Customer::recomputedBalancesForIds($ids);
            $stored = Customer::whereIn('id', $ids)->pluck('credit_balance', 'id');
            foreach ($ids as $id) {
                $new = $balances[$id] ?? 0.0;
                $old = (float) ($stored[$id] ?? 0);
                if (abs($old - $new) > 0.004) {
                    Customer::where('id', $id)->update(['credit_balance' => $new]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Sync push balance reconciliation failed; stored balances will catch up on the next push, ledger visit, or reconcile run.', ['error' => $e->getMessage()]);
        }
    }

    /** GET /api/sync/pull?since=&limit=&cursor= — oldest first,
     *  cursor-paginated. Response shape per contract, plus the
     *  additive `flags` array of unresolved sync_flags. */
    public function pull(Request $request): JsonResponse
    {
        $registry = $this->registry();
        $tableOrder = array_keys($registry);

        $since = null;
        if ($request->query('since') !== null && $request->query('since') !== '') {
            try {
                $since = Carbon::parse($request->query('since'))->utc();
            } catch (Throwable) {
                return $this->error('validation_failed', 'Query parameter "since" is not a valid ISO-8601 timestamp.', 422);
            }
        }

        $limit = (int) $request->query('limit', self::PULL_LIMIT_DEFAULT);
        $limit = max(1, min($limit, self::PULL_LIMIT_MAX));

        $cursor = null;
        if ($request->query('cursor') !== null && $request->query('cursor') !== '') {
            $cursor = $this->decodeCursor((string) $request->query('cursor'));
            if ($cursor === null) {
                return $this->error('validation_failed', 'Query parameter "cursor" is not a valid pull cursor.', 422);
            }
        }

        // Per-table fetch of limit+1 rows, then a merged oldest-first
        // slice. Fetching limit+1 per table (not just limit overall)
        // is what makes has_more exact: if more than `limit` rows
        // remain anywhere, the merged set holds at least limit+1.
        $merged = [];
        foreach ($registry as $table => $config) {
            $query = $this->baseQuery($config)->whereNotNull('client_uuid');

            if ($cursor !== null) {
                // Strict tuple comparison on (updated_at, table
                // order, id): rows after the last delivered change.
                // A cursor page ignores `since` — the cursor already
                // encodes the position.
                $cursorIndex = $cursor['t'];
                $tableIndex = array_search($table, $tableOrder, true);
                if ($tableIndex < $cursorIndex) {
                    $query->where('updated_at', '>', $cursor['u']);
                } elseif ($tableIndex === $cursorIndex) {
                    $query->where(function ($q) use ($cursor) {
                        $q->where('updated_at', '>', $cursor['u'])
                            ->orWhere(function ($q2) use ($cursor) {
                                $q2->where('updated_at', '=', $cursor['u'])
                                    ->where('id', '>', $cursor['i']);
                            });
                    });
                } else {
                    $query->where('updated_at', '>=', $cursor['u']);
                }
            } elseif ($since !== null) {
                // Deliberately >=, not >: a row updated in the exact
                // second the client recorded as last_pulled_at must
                // re-deliver (harmless — the client upserts by
                // client_uuid) rather than risk being skipped.
                $query->where('updated_at', '>=', $since);
            }

            foreach ($query->orderBy('updated_at')->orderBy('id')->take($limit + 1)->get() as $row) {
                $merged[] = [
                    'table' => $table,
                    'table_index' => array_search($table, $tableOrder, true),
                    'row' => $row,
                ];
            }
        }

        usort($merged, function ($a, $b) {
            $timeCmp = $a['row']->updated_at->timestamp <=> $b['row']->updated_at->timestamp;
            if ($timeCmp !== 0) {
                return $timeCmp;
            }
            $tableCmp = $a['table_index'] <=> $b['table_index'];
            if ($tableCmp !== 0) {
                return $tableCmp;
            }

            return $a['row']->id <=> $b['row']->id;
        });

        $hasMore = count($merged) > $limit;
        $page = array_slice($merged, 0, $limit);

        $uuidCache = [];
        $changes = [];
        $lastKey = null;
        foreach ($page as $item) {
            $row = $item['row'];
            if (empty($row->client_uuid)) {
                continue; // Defensive only: migration 000001 backfills every row.
            }
            $changes[] = [
                'table' => $item['table'],
                'client_uuid' => $row->client_uuid,
                'operation' => $this->isTrashed($row) ? 'delete' : 'upsert',
                'updated_at' => $this->iso($row->updated_at),
                'server_id' => $row->id,
                'data' => $this->isTrashed($row) ? null : $this->pullDataFor($item['table'], $row, $registry, $uuidCache),
            ];
            $lastKey = [
                'u' => $this->iso($row->updated_at),
                't' => $item['table_index'],
                'i' => $row->id,
            ];
        }

        // Deletes carry no data in the contract shape; the desktop
        // only needs table + client_uuid to tombstone locally.
        foreach ($changes as &$change) {
            if ($change['operation'] === 'delete') {
                unset($change['data']);
            }
        }
        unset($change);

        $flags = SyncFlag::unresolved()
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(self::FLAG_LIMIT)
            ->get()
            ->map(fn ($flag) => [
                'table' => $flag->table_name,
                'client_uuid' => $flag->client_uuid,
                'reason' => $flag->reason,
            ])->all();

        return response()->json([
            'changes' => $changes,
            'cursor' => ($hasMore && $lastKey !== null) ? $this->encodeCursor($lastKey) : null,
            'has_more' => $hasMore,
            'server_time' => $this->iso(Carbon::now('UTC')),
            'flags' => $flags,
        ]);
    }

    /** GET /api/sync/ping — cheap authenticated check for the
     *  desktop Test Connection button. */
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'server_time' => $this->iso(Carbon::now('UTC')),
        ]);
    }

    // ----------------------------------------------------------------
    // Push: per-row application
    // ----------------------------------------------------------------

    /** Applies one change. Outcomes: accepted/rejected appended
     *  directly, or the change appended to $deferred when (and only
     *  when) a foreign key cannot yet be resolved. */
    private function applyChange(array $change, array $registry, array &$accepted, array &$rejected, array &$deferred, array &$touchedProductIds): void
    {
        $table = $change['table'] ?? null;
        $clientUuid = $change['client_uuid'] ?? null;
        $operation = $change['operation'] ?? null;

        $reject = function (string $code, string $message) use ($table, $clientUuid, &$rejected) {
            $rejected[] = ['table' => $table, 'client_uuid' => $clientUuid, 'code' => $code, 'message' => $message];
        };

        try {
            if (!is_string($table) || !isset($registry[$table])) {
                $reject('unknown_table', 'Table is not syncable.');
                return;
            }
            if (!is_string($clientUuid) || !Str::isUuid($clientUuid)) {
                $reject('validation_failed', 'client_uuid must be a UUID.');
                return;
            }
            if ($operation !== 'upsert' && $operation !== 'delete') {
                $reject('validation_failed', 'operation must be "upsert" or "delete".');
                return;
            }
            if (!isset($change['updated_at'])) {
                $reject('validation_failed', 'updated_at is required.');
                return;
            }
            try {
                $incomingAt = Carbon::parse($change['updated_at'])->utc();
            } catch (Throwable) {
                $reject('validation_failed', 'updated_at is not a valid ISO-8601 timestamp.');
                return;
            }

            $config = $registry[$table];
            $existing = $this->baseQuery($config)->where('client_uuid', $clientUuid)->first();

            if ($operation === 'delete') {
                $this->applyDelete($existing, $incomingAt, $table, $clientUuid, $accepted, $reject);
                return;
            }

            $data = $change['data'] ?? null;
            if (!is_array($data)) {
                $reject('validation_failed', 'An upsert change must carry a "data" object.');
                return;
            }

            // Stock movements are insert-only and decided purely by
            // payload: identical payload = idempotent ACK, different
            // payload = duplicate_movement. They are never updated,
            // whatever updated_at says.
            if (!empty($config['insert_only']) && $existing !== null) {
                $resolved = $this->resolveFks($config, $data, $registry);
                if ($resolved === null) {
                    $deferred[] = $change;
                    return;
                }
                if ($this->movementPayloadMatches($existing, $config, $data, $resolved)) {
                    $accepted[] = ['table' => $table, 'client_uuid' => $clientUuid, 'server_id' => $existing->id];
                } else {
                    $reject('duplicate_movement', 'A movement with this client_uuid already exists with different content; movements are insert-only.');
                }
                return;
            }

            if ($existing !== null) {
                // Idempotent resend: same client_uuid + updated_at
                // already stored = ACK, never a duplicate row. (For
                // a trashed row this falls through to restore+apply
                // below, since the stored version is the tombstone,
                // not the row content.)
                $trashed = $this->isTrashed($existing);
                if (!$trashed && $existing->updated_at->equalTo($incomingAt)) {
                    $accepted[] = ['table' => $table, 'client_uuid' => $clientUuid, 'server_id' => $existing->id];
                    return;
                }
                // Uniform server-wins: a newer server row rejects
                // the push for ALL tables, money and master data.
                if ($existing->updated_at->greaterThan($incomingAt)) {
                    $reject('conflict_server_wins', 'Server row is newer; pull first.');
                    return;
                }
            }

            $resolved = $this->resolveFks($config, $data, $registry);
            if ($resolved === null) {
                $deferred[] = $change;
                return;
            }

            $attributes = $this->buildAttributes($config, $data, $resolved);

            if ($existing !== null) {
                if ($this->isTrashed($existing)) {
                    $existing->restore();
                }
                $existing->timestamps = false;
                $existing->forceFill($attributes);
                $existing->forceFill(['updated_at' => $incomingAt]);
                $existing->save();
                $accepted[] = ['table' => $table, 'client_uuid' => $clientUuid, 'server_id' => $existing->id];
            } else {
                /** @var Model $model */
                $model = new $config['model']();
                $model->timestamps = false;
                $model->forceFill($attributes);
                $model->forceFill([
                    'client_uuid' => $clientUuid,
                    'created_at' => $incomingAt,
                    'updated_at' => $incomingAt,
                ]);
                $model->save();
                $accepted[] = ['table' => $table, 'client_uuid' => $clientUuid, 'server_id' => $model->id];

                if ($table === 'stock_movements') {
                    $this->applyMovementStock($model, $touchedProductIds);
                }
            }
        } catch (Throwable $e) {
            // One bad row must never fail the batch. Database
            // rejections (unique sku/order_number, NOT NULL, bad
            // enum) land here as per-row validation failures.
            Log::warning('Sync push row rejected with exception.', [
                'table' => $table, 'client_uuid' => $clientUuid, 'error' => $e->getMessage(),
            ]);
            $reject('validation_failed', 'Row could not be saved: '.$e->getMessage());
        }
    }

    private function applyDelete(?Model $existing, Carbon $incomingAt, ?string $table, ?string $clientUuid, array &$accepted, callable $reject): void
    {
        if ($existing === null) {
            // Deleting a row the server never saw is a no-op ACK:
            // idempotent deletes must not error.
            $accepted[] = ['table' => $table, 'client_uuid' => $clientUuid, 'server_id' => null];
            return;
        }
        if ($this->isTrashed($existing)) {
            $accepted[] = ['table' => $table, 'client_uuid' => $existing->client_uuid, 'server_id' => $existing->id];
            return;
        }
        if ($existing->updated_at->greaterThan($incomingAt)) {
            $reject('conflict_server_wins', 'Server row is newer; pull first.');
            return;
        }
        // SoftDeletes models move to Trash; every other model is
        // hard-deleted (and therefore invisible to pull — see the
        // class docblock). Observers fire either way, same as a web
        // delete, so journal reversals behave identically.
        $existing->delete();
        $accepted[] = ['table' => $table, 'client_uuid' => $existing->client_uuid, 'server_id' => $existing->id];
    }

    /** SoftDeletes check that actually calls the trait method:
     *  `$row->trashed` as a property never invokes it, so a plain
     *  property read would report every soft-deleted row as live and
     *  pull would never surface deletes. */
    private function isTrashed(?Model $row): bool
    {
        return $row !== null && method_exists($row, 'trashed') && $row->trashed();
    }

    /** Resolves every *_client_uuid present in $data to an integer id.
     *  Returns null (→ defer to pass 2) when a referenced row is not
     *  on the server yet. A key ABSENT from $data is not a failure:
     *  on update it leaves the existing link untouched, on create it
     *  leaves the column null (or its DB default). An explicit null
     *  unlinks. Trashed parents still resolve — the row exists. */
    private function resolveFks(array $config, array $data, array $registry): ?array
    {
        $columns = [];
        foreach ($config['fks'] as $wireKey => [$column, $targetTable]) {
            if (!array_key_exists($wireKey, $data)) {
                continue;
            }
            $targetUuid = $data[$wireKey];
            if ($targetUuid === null) {
                $columns[$column] = null;
                continue;
            }
            if (!is_string($targetUuid) || !Str::isUuid($targetUuid)) {
                throw new \InvalidArgumentException("{$wireKey} must be a UUID or null.");
            }
            $targetConfig = $registry[$targetTable];
            $target = $this->baseQuery($targetConfig)->where('client_uuid', $targetUuid)->first();
            if ($target === null) {
                return null;
            }
            $columns[$column] = $target->id;
        }

        return $columns;
    }

    /** Whitelisted scalar attributes only. Unknown keys (including
     *  any `stock` key on a product row, and any *_client_uuid key)
     *  are ignored, never assigned. Money and quantities are rounded
     *  to 2dp, matching the server's own rule (DESIGN.md §2). */
    private function buildAttributes(array $config, array $data, array $resolvedFkColumns): array
    {
        $attributes = [];
        foreach ($config['fields'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (is_bool($value)) {
                $attributes[$field] = $value;
            } elseif (is_numeric($value)) {
                $attributes[$field] = round((float) $value, 2);
            } else {
                $attributes[$field] = $value;
            }
        }

        return array_merge($attributes, $resolvedFkColumns);
    }

    /** Payload comparison for an existing movement: whitelisted
     *  scalars (numerically compared, so "2" and 2.00 match) plus
     *  resolved FK columns. */
    private function movementPayloadMatches(Model $existing, array $config, array $data, array $resolvedFkColumns): bool
    {
        foreach ($config['fields'] as $field) {
            if (!array_key_exists($field, $data)) {
                return false;
            }
            $incoming = $data[$field];
            $stored = $existing->getAttribute($field);
            if (is_numeric($incoming) && is_numeric($stored)) {
                if (round((float) $incoming, 2) !== round((float) $stored, 2)) {
                    return false;
                }
            } elseif ($incoming != $stored) {
                return false;
            }
        }
        foreach ($resolvedFkColumns as $column => $id) {
            if ((int) $existing->getAttribute($column) !== (int) $id) {
                return false;
            }
        }

        return true;
    }

    /** Applies a newly created movement to products.stock by its
     *  signed quantity (sale quantities are negative). Uses the
     *  query builder on purpose: this is a side effect of the
     *  movement, not a product edit, so the product's own updated_at
     *  is left alone. Absolute stock values are never accepted. */
    private function applyMovementStock(Model $movement, array &$touchedProductIds): void
    {
        $productId = $movement->getAttribute('product_id');
        if ($productId === null) {
            return;
        }
        DB::table('products')->where('id', $productId)->increment('stock', (float) $movement->getAttribute('quantity'));
        $touchedProductIds[(int) $productId] = true;
    }

    /** After a batch, any touched product now sitting below zero gets
     *  one open negative_stock flag (never a duplicate open flag for
     *  the same product). Silent acceptance of negative stock is
     *  what this replaces. */
    private function flagNegativeStock(array $touchedProductIds): void
    {
        foreach (array_keys($touchedProductIds) as $productId) {
            $product = DB::table('products')->where('id', $productId)->first();
            if ($product === null || (float) $product->stock >= 0 || empty($product->client_uuid)) {
                continue;
            }
            $alreadyFlagged = SyncFlag::unresolved()
                ->where('table_name', 'products')
                ->where('client_uuid', $product->client_uuid)
                ->where('reason', 'negative_stock')
                ->exists();
            if ($alreadyFlagged) {
                continue;
            }
            SyncFlag::create([
                'table_name' => 'products',
                'client_uuid' => $product->client_uuid,
                'reason' => 'negative_stock',
                'data' => ['stock' => (float) $product->stock],
            ]);
        }
    }

    // ----------------------------------------------------------------
    // Pull helpers
    // ----------------------------------------------------------------

    private function baseQuery(array $config)
    {
        $query = $config['model']::query();
        if (!empty($config['soft'])) {
            $query = $query->withTrashed();
        }

        return $query;
    }

    /** Pull data for one row: whitelisted scalars + pull-only/pull-
     *  extra columns + FKs translated back to *_client_uuid. Rows
     *  whose linked parent was force-deleted report null for that
     *  link. */
    private function pullDataFor(string $table, Model $row, array $registry, array &$uuidCache): array
    {
        $config = $registry[$table];
        $data = [];

        $fields = array_merge(
            $config['fields'],
            $config['pull_only'] ?? [],
            $config['pull_extra'] ?? [],
        );
        foreach ($fields as $field) {
            $value = $row->getAttribute($field);
            if ($value === null) {
                $data[$field] = null;
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$field] = in_array($field, self::DATE_ONLY_FIELDS, true)
                    ? $value->format('Y-m-d')
                    : $this->iso(Carbon::instance($value)->utc());
            } elseif (is_bool($value)) {
                $data[$field] = $value;
            } elseif (is_numeric($value)) {
                $data[$field] = (float) $value;
            } else {
                $data[$field] = $value;
            }
        }

        foreach ($config['fks'] as $wireKey => [$column, $targetTable]) {
            $targetId = $row->getAttribute($column);
            if ($targetId === null) {
                $data[$wireKey] = null;
                continue;
            }
            $cacheKey = $targetTable.':'.$targetId;
            if (!array_key_exists($cacheKey, $uuidCache)) {
                $targetConfig = $registry[$targetTable];
                $target = $this->baseQuery($targetConfig)->where('id', $targetId)->first();
                $uuidCache[$cacheKey] = $target !== null ? $target->client_uuid : null;
            }
            $data[$wireKey] = $uuidCache[$cacheKey];
        }

        return $data;
    }

    private function encodeCursor(array $key): string
    {
        return rtrim(strtr(base64_encode(json_encode($key)), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): ?array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['u'], $decoded['t'], $decoded['i'])) {
            return null;
        }
        try {
            $at = Carbon::parse($decoded['u'])->utc();
        } catch (Throwable) {
            return null;
        }

        return ['u' => $at, 't' => (int) $decoded['t'], 'i' => (int) $decoded['i']];
    }

    /** UTC ISO-8601 with a Z suffix, exactly as the contract shows. */
    private function iso(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
