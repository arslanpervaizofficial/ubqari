<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Worker;
use Illuminate\Http\Request;

/** Workers — bill attribution only (see Worker model).
 *
 *  Visibility rule, set by the shop owner 2026-11-01: the list is
 *  visible to EVERY authenticated role (admin, manager, cashier all
 *  see the workers, because any of them may type a worker code at
 *  billing time), but ONLY an admin can add, edit, disable or delete
 *  a worker. Every management action below aborts with 403 for
 *  non-admins; the sidebar link itself is outside the admin-only
 *  block for the same reason. */
class WorkerController extends Controller
{
    public function index()
    {
        $workers = Worker::orderBy('name')->paginate(20);
        $orderCounts = Order::selectRaw('worker_code, COUNT(*) as c')
            ->whereNotNull('worker_code')
            ->groupBy('worker_code')
            ->pluck('c', 'worker_code');
        return view('workers.index', compact('workers', 'orderCounts'));
    }

    public function create()
    {
        $this->requireAdmin();
        return view('workers.create');
    }

    public function store(Request $request)
    {
        $this->requireAdmin();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:workers,code'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        Worker::create($data);
        return redirect()->route('workers.index')->with('success', __('Worker added.'));
    }

    public function edit(Worker $worker)
    {
        $this->requireAdmin();
        return view('workers.edit', compact('worker'));
    }

    public function update(Request $request, Worker $worker)
    {
        $this->requireAdmin();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:workers,code,' . $worker->id],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $worker->update($data);
        return redirect()->route('workers.index')->with('success', __('Worker updated.'));
    }

    public function destroy(Worker $worker)
    {
        $this->requireAdmin();
        // Safe: orders keep worker_code/worker_name as plain text.
        $worker->delete();
        return redirect()->route('workers.index')->with('success', __('Worker deleted.'));
    }

    public function toggleActive(Worker $worker)
    {
        $this->requireAdmin();
        $worker->update(['is_active' => !$worker->is_active]);
        return back()->with('success', __('Worker status updated.'));
    }

    private function requireAdmin(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }
}
