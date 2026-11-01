<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function show(Worker $worker)
    {
        // Visible to every authenticated role, like the list: any role
        // may need to check who a worker is. Management stays admin-only.
        // Stats follow the CURRENT code: orders keep code + name as
        // plain text, so a code changed later leaves old orders behind.
        $base = Order::where('worker_code', $worker->code);
        $orders = (clone $base)->latest()->take(10)->get();
        $ordersCount = (clone $base)->count();
        $totalBilled = (clone $base)->sum('total');
        return view('workers.show', compact('worker', 'orders', 'ordersCount', 'totalBilled'));
    }

    public function create()
    {
        $this->requireAdmin();
        return view('workers.create');
    }

    public function store(Request $request)
    {
        $this->requireAdmin();
        $request->merge(['phone' => self::normalisePhone($request->input('phone'))]);
        $data = $request->validate($this->rules());
        $data['is_active'] = $request->boolean('is_active');
        $data['image'] = $this->handleImageUpload($request);
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
        $request->merge(['phone' => self::normalisePhone($request->input('phone'))]);
        $data = $request->validate($this->rules($worker->id));
        $data['is_active'] = $request->boolean('is_active');
        $data['image'] = $this->handleImageUpload($request, $worker);
        $worker->update($data);
        return redirect()->route('workers.index')->with('success', __('Worker updated.'));
    }

    public function destroy(Worker $worker)
    {
        $this->requireAdmin();
        // Safe: orders keep worker_code/worker_name as plain text.
        // The photo is worker-only data, so it goes with the worker.
        if ($worker->image) {
            $path = public_path('uploads/workers/' . $worker->image);
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $worker->delete();
        return redirect()->route('workers.index')->with('success', __('Worker deleted.'));
    }

    public function toggleActive(Worker $worker)
    {
        $this->requireAdmin();
        $worker->update(['is_active' => !$worker->is_active]);
        return back()->with('success', __('Worker status updated.'));
    }

    /** $id excludes the record being edited from the uniqueness
     *  checks, so updating a worker without changing its own code,
     *  CNIC or email doesn't flag itself as a duplicate. Phone is
     *  normalised to digits only before this runs (see store/update),
     *  so "0300-123 4567" and "03001234567" are the same number. */
    private function rules($id = null): array
    {
        $ignore = $id ? ',' . $id : '';
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:workers,code' . $ignore],
            'phone' => ['required', 'string', 'regex:/^[0-9]{7,15}$/'],
            'cnic' => ['required', 'string', 'regex:/^\d{5}-\d{7}-\d{1}$/', 'unique:workers,cnic' . $ignore],
            'email' => ['nullable', 'email', 'max:255', 'unique:workers,email' . $ignore],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    private static function normalisePhone($phone): string
    {
        if (!is_string($phone)) {
            return '';
        }
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /** Photo upload, same pattern as CustomerController: public
     *  uploads/workers, random filename, extension detected from the
     *  file's actual MIME type (never the client-supplied one), old
     *  photo deleted on replace. No file -> keep the current photo. */
    private function handleImageUpload(Request $request, Worker $worker = null): ?string
    {
        if (!$request->hasFile('image')) {
            return $worker->image ?? null;
        }

        $dir = public_path('uploads/workers');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($worker && $worker->image && file_exists($dir . '/' . $worker->image)) {
            @unlink($dir . '/' . $worker->image);
        }

        $file = $request->file('image');
        $filename = Str::random(20) . '.' . $file->extension();
        $file->move($dir, $filename);

        return $filename;
    }

    private function requireAdmin(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }
}
