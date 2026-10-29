<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FEATURE 2 — Physical Stock Counting (v3.0).
 *
 * NEW controller only: no existing model or controller is edited.
 *
 * Flow:
 *  1. create/store — snapshot EVERY product's current products.stock
 *     into one item per product (snapshot_stock). Counted figures
 *     entered on the create page's visible page are saved with it;
 *     every other product starts uncounted (counted_stock NULL).
 *  2. edit/update — enter or correct counted quantities, one paginated
 *     page at a time. Blank = not counted yet (clears a previous
 *     figure back to NULL). Only the submitted page's rows are
 *     touched; all other rows are left exactly as they were.
 *  3. show — variance report: counted rows only (product, snapshot,
 *     counted, variance = counted - snapshot).
 *  4. approve — admin/manager only. Inside one DB transaction, every
 *     counted row's product row is locked and the APPLIED variance
 *     (counted minus CURRENT stock, v3.1.0 M7 — not minus the
 *     snapshot) is posted: stock is set to the counted figure under
 *     the lock and exactly one StockMovement of type 'adjustment'
 *     with reason "Stock count #N" and the applied quantity is
 *     written — no other movement type is ever used here. A zero
 *     applied delta writes no movement (v3.1.0 L11a). The snapshot
 *     variance stays report-only on the item row. Uncounted rows are
 *     never touched. The count then becomes 'approved' and can never
 *     be approved again or edited.
 *  5. destroy — open counts only. The cascade on stock_count_items
 *     removes the items; products.stock is never touched.
 *
 * Guards: an approved count is refused in edit/update/approve/destroy
 * even if someone posts directly (not just by hiding buttons), and
 * approve() has an in-controller admin/manager gate in addition to
 * the route middleware in routes-fragment.txt, so the gate holds
 * whichever group the coordinator merges the routes into.
 */
class StockCountController extends Controller
{
    public function index()
    {
        $counts = StockCount::with('user')
            ->withCount('items')
            ->withCount(['items as counted_items_count' => fn ($q) => $q->whereNotNull('counted_stock')])
            ->latest('count_date')
            ->latest('id')
            ->paginate(20);

        return view('stock_counts.index', compact('counts'));
    }

    /** Paginated preview of every product that will be snapshotted. */
    public function create()
    {
        $products = Product::orderBy('name')->paginate(50);

        return view('stock_counts.create', compact('products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'count_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            // Keyed by product id; only the create page's visible page
            // can be submitted (plain HTML forms submit one page).
            'counted' => ['nullable', 'array'],
            'counted.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $submitted = $data['counted'] ?? [];

        $count = DB::transaction(function () use ($data, $submitted) {
            $count = StockCount::create([
                'count_date' => $data['count_date'],
                'status' => StockCount::STATUS_OPEN,
                'user_id' => auth()->id(),
                'note' => $data['note'] ?? null,
            ]);

            // One item per product, snapshot = current system stock.
            // "All products" is literal here: inactive products are
            // included too, so a count can surface stock sitting on a
            // deactivated product instead of silently skipping it.
            foreach (Product::orderBy('name')->get() as $product) {
                $counted = $this->submittedCounted($submitted, $product);
                $count->items()->create([
                    'product_id' => $product->id,
                    'snapshot_stock' => $product->stock,
                    'counted_stock' => $counted,
                    // variance is recomputed by StockCountItem's
                    // saving event; it is never accepted from input.
                ]);
            }

            return $count;
        });

        return redirect()->route('stock-counts.edit', $count)
            ->with('status', "Stock count #{$count->id} created with a snapshot of all products. Enter the counted quantities below.");
    }

    /** Variance report: counted rows only. */
    public function show(StockCount $stockCount)
    {
        $stockCount->load('user');
        $stockCount->loadCount('items');

        $countedItems = $stockCount->items()
            ->whereNotNull('counted_stock')
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->orderBy('products.name')
            ->select('stock_count_items.*')
            ->with('product')
            ->paginate(50);

        $totalVariance = (float) $stockCount->items()
            ->whereNotNull('counted_stock')
            ->sum('variance');

        $canApprove = $stockCount->isOpen()
            && auth()->user()
            && (auth()->user()->isAdmin() || auth()->user()->isManager());

        return view('stock_counts.show', compact(
            'stockCount', 'countedItems', 'totalVariance', 'canApprove'
        ));
    }

    /** Paginated counted-quantity entry form (open counts only). */
    public function edit(StockCount $stockCount)
    {
        if ($stockCount->isApproved()) {
            return redirect()->route('stock-counts.show', $stockCount)
                ->withErrors(['error' => 'An approved count cannot be edited.']);
        }

        $items = $stockCount->items()
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->orderBy('products.name')
            ->select('stock_count_items.*')
            ->with('product')
            ->paginate(50)
            ->withQueryString();

        return view('stock_counts.edit', compact('stockCount', 'items'));
    }

    /** Saves only the submitted page's rows; other rows are untouched. */
    public function update(Request $request, StockCount $stockCount)
    {
        if ($stockCount->isApproved()) {
            return back()->withErrors(['error' => 'An approved count cannot be edited.']);
        }

        $data = $request->validate([
            // Keyed by stock_count_item id.
            'counted' => ['nullable', 'array'],
            'counted.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $submitted = $data['counted'] ?? [];

        DB::transaction(function () use ($stockCount, $submitted) {
            $items = StockCountItem::with('product')
                ->where('stock_count_id', $stockCount->id)
                ->whereIn('id', array_keys($submitted))
                ->get();

            foreach ($items as $item) {
                $raw = $submitted[$item->id];
                // Blank = not counted yet: clears any previous figure
                // back to NULL (and variance back to NULL with it).
                $item->counted_stock = $raw === '' || $raw === null
                    ? null
                    : $item->product->roundQuantity((float) $raw);
                $item->save(); // saving event recomputes variance
            }
        });

        return back()->with('status', 'Counted quantities saved.');
    }

    /**
     * Posts counted figures to stock. Admin/manager only, open counts
     * only, one transaction, counted rows only, 'adjustment' movements
     * only. A second approve attempt is refused, not re-applied.
     */
    public function approve(StockCount $stockCount)
    {
        $user = auth()->user();
        if (!$user || !($user->isAdmin() || $user->isManager())) {
            abort(403);
        }

        if ($stockCount->isApproved()) {
            return back()->withErrors(['error' => 'This count has already been approved.']);
        }

        $adjusted = DB::transaction(function () use ($stockCount) {
            // Re-check inside the transaction, locked, so two
            // simultaneous approve clicks cannot both post stock.
            // Returns -1 when another request approved it first.
            $count = StockCount::lockForUpdate()->findOrFail($stockCount->id);
            if ($count->isApproved()) {
                return -1;
            }

            $adjusted = 0;
            // Locked in product-id order (deadlock-safe) below, so the
            // loop sorts by product first. One item per product exists
            // per count, so this ordering is total.
            $items = $count->items()->whereNotNull('counted_stock')->with('product')->get()->sortBy('product_id')->values();

            foreach ($items as $item) {
                // v3.1.0 (M7): lock the product row and compute the
                // variance against CURRENT stock, not the snapshot.
                // The snapshot variance (counted - snapshot) stays
                // REPORT-ONLY on the item row (the saving event
                // recomputes it on save); what is actually APPLIED is
                // counted - current. The old code set stock to the
                // counted figure outright, silently discarding every
                // sale/purchase that happened between snapshot and
                // approve. Now only the counted-vs-current difference
                // moves, so concurrent stock activity survives the
                // count. Setting stock to the counted figure under
                // the product lock is exactly that delta applied.
                $product = Product::lockForUpdate()->find($item->product_id);
                if (!$product) {
                    continue; // defensive only: the FK normally prevents this
                }

                $applied = round((float) $item->counted_stock - (float) $product->stock, 2);

                // Recompute in case the row was saved before the
                // saving-event rule existed; normally a no-op. This is
                // the REPORT variance (counted - snapshot), untouched
                // by the applied-delta change above.
                $item->variance = round((float) $item->counted_stock - (float) $item->snapshot_stock, 2);
                $item->save();

                // v3.1.0 (L11a): a zero applied delta writes NO
                // movement row and counts as no adjustment — a
                // 0-quantity movement is noise in the history, and
                // the product's stock is already exactly the counted
                // figure (within rounding).
                if (abs($applied) <= 0.004) {
                    continue;
                }

                $product->update(['stock' => $item->counted_stock]);

                // v3.1.0 (H7): the applied delta is mirrored in
                // batches, in this same transaction.
                ProductBatch::mirrorStockDelta((int) $item->product_id, $applied, ['record' => "Stock count #{$count->id}"]);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => 'adjustment',
                    'quantity' => $applied,
                    'reason' => "Stock count #{$count->id}",
                    'user_id' => auth()->id(),
                ]);
                $adjusted++;
            }

            $count->update(['status' => StockCount::STATUS_APPROVED]);

            return $adjusted;
        });

        if ($adjusted === -1) {
            return back()->withErrors(['error' => 'This count has already been approved.']);
        }

        return redirect()->route('stock-counts.show', $stockCount)
            ->with('status', "Stock count #{$stockCount->id} approved: {$adjusted} product(s) adjusted to their counted quantities.");
    }

    /** Open counts only; cascade removes the items; stock untouched. */
    public function destroy(StockCount $stockCount)
    {
        if ($stockCount->isApproved()) {
            return back()->withErrors(['error' => 'An approved count cannot be deleted.']);
        }

        $stockCount->delete();

        return redirect()->route('stock-counts.index')
            ->with('status', "Stock count #{$stockCount->id} deleted. No stock was changed.");
    }

    /**
     * Normalises one submitted counted value for a product: blank or
     * absent = not counted yet (NULL); otherwise the app's own unit
     * rounding (whole numbers for piece/box/etc., 2 decimals otherwise).
     */
    protected function submittedCounted(array $submitted, Product $product): ?float
    {
        if (!array_key_exists($product->id, $submitted)) {
            return null;
        }

        $raw = $submitted[$product->id];

        return $raw === '' || $raw === null
            ? null
            : $product->roundQuantity((float) $raw);
    }
}
