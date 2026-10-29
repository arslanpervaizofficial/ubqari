<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * v3.0.3 — Dedicated Batches / Expiry page.
 *
 * Why this exists: the sidebar "Batches / Expiry" link used to point at
 * dashboard#expiring-soon, but that dashboard section only renders when
 * at least one batch is expiring (@if($expiringBatches->count())). With
 * no expiring batch the anchor target does not exist in the HTML, so
 * the browser just stays at the top of the dashboard — exactly the
 * reported "click karne par dashboard hi show ho raha" behaviour.
 * This page always exists, whatever the data looks like.
 *
 * Filters: all (remaining stock only) | expiring (the same
 * expiringSoon scope the dashboard uses: expiry set, within 30 days,
 * stock left — already-expired batches included on purpose) | expired
 * (expiry date past, stock left). Search matches batch_no or product
 * name. Product stock stays the authority; this page is read-only.
 */
class ProductBatchController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->get('filter', 'all');
        if (!in_array($filter, ['all', 'expiring', 'expired'], true)) {
            $filter = 'all';
        }
        $search = trim((string) $request->get('search', ''));

        $migrated = Schema::hasTable('product_batches');
        $batches = null;

        if ($migrated) {
            $query = ProductBatch::with('product')->where('remaining_quantity', '>', 0);

            if ($filter === 'expiring') {
                $query->expiringSoon();
            } elseif ($filter === 'expired') {
                $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<', now()->toDateString());
            }

            if ($search !== '') {
                $query->where(function ($w) use ($search) {
                    $w->where('batch_no', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($p) use ($search) {
                            $p->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $batches = $query->fifo()->paginate(20)->withQueryString();
        }

        return view('batches.index', compact('batches', 'filter', 'search', 'migrated'));
    }

    /**
     * v3.0.4 — Edit form for one batch. Only batch_no and expiry_date
     * are editable on purpose: quantity / remaining_quantity are
     * bookkeeping derived from receives, sales and returns, and editing
     * them by hand would corrupt FIFO consumption. Expiry changes take
     * effect on the next sale automatically (the fifo scope orders by
     * expiry_date). The edit itself is audit-logged (see
     * AppServiceProvider: ProductBatch is observed, updated = changed
     * attributes only), so a corrected expiry always leaves a trail.
     */
    public function edit(ProductBatch $batch)
    {
        $batch->load('product');

        return view('batches.edit', compact('batch'));
    }

    public function update(Request $request, ProductBatch $batch)
    {
        $data = $request->validate([
            'batch_no' => ['required', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $expiry = $data['expiry_date'] ?? null;
        if ($expiry === '') {
            $expiry = null;
        }

        $batch->update([
            'batch_no' => trim($data['batch_no']),
            'expiry_date' => $expiry,
        ]);

        return redirect()->route('batches.index')
            ->with('status', __('Batch updated'));
    }
}
