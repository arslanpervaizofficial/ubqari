@extends('layouts.app')
@section('title', 'Backup & Restore')
@section('content')
<h1 class="text-2xl font-bold mb-4">Backup &amp; Restore</h1>

<div class="bg-white p-6 rounded-xl shadow-sm w-full mb-6">
    <h2 class="font-semibold text-gray-800 mb-1">Full Backup</h2>
    <p class="text-sm text-gray-500 mb-3">
        Downloads everything in the system — products, customers, suppliers, users, orders, purchase orders, stock movements and returns — as one file.
    </p>
    <a href="{{ route('backup.download', ['type' => 'full']) }}" class="btn btn-dark">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
        Download Full Backup
    </a>
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full mb-6">
    <h2 class="font-semibold text-gray-800 mb-1">Backup a Date Range</h2>
    <p class="text-sm text-gray-500 mb-3">
        Only sales, purchases, stock movements and returns from the dates you pick are included — products/customers/suppliers/users come along in full too, since the transactions in that range reference them.
    </p>
    <form method="GET" action="{{ route('backup.download') }}" class="flex flex-wrap items-end gap-2">
        <input type="hidden" name="type" value="range">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" id="range-from" required class="border rounded px-3">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" id="range-to" required class="border rounded px-3">
        </div>
        <button type="button" class="btn btn-gray" data-quick-range="week">Last 7 Days</button>
        <button type="button" class="btn btn-gray" data-quick-range="month">This Month</button>
        <button class="btn btn-dark">Download Range Backup</button>
    </form>
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full">
    <h2 class="font-semibold text-gray-800 mb-1">Restore from Backup</h2>
    <p class="text-sm text-gray-500 mb-3">
        Restoring a <strong>Full</strong> backup replaces everything currently in the system — you'll be logged out and need to sign back in with an account from that backup.
        Restoring a <strong>date-range</strong> backup merges those records in without touching anything outside that range.
    </p>
    <form method="POST" action="{{ route('backup.restore') }}" enctype="multipart/form-data" novalidate
          class="flex flex-wrap items-end gap-2 confirm-submit"
          data-confirm-message="Restore from this backup file? This will overwrite matching records — make sure you have a current backup first if you're not sure.">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Backup file (.json)</label>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-gray" onclick="document.getElementById('backup-file-input').click()">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                    Choose File
                </button>
                <span id="backup-file-name" class="text-sm text-gray-500">No file chosen</span>
            </div>
            <input type="file" id="backup-file-input" name="backup_file" accept="application/json,.json" required data-label="Backup file" class="hidden">
        </div>
        <button class="btn btn-solid-yellow">Restore Backup</button>
    </form>
    @error('backup_file') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full mt-6 border-2 border-red-200">
    <h2 class="font-semibold text-red-700 mb-1">Danger Zone — Reset Database</h2>
    <p class="text-sm text-gray-500 mb-3">
        Permanently erases <strong>everything</strong> — products, customers, suppliers, orders, purchase orders, stock movements, payments, stock returns, and every user account. This cannot be undone from inside the app; the only way back is restoring a backup taken beforehand.
        A single default admin account (<code class="bg-gray-100 px-1 rounded">admin@ubqari.pos</code> / <code class="bg-gray-100 px-1 rounded">password</code>) is recreated automatically so the system isn't left completely locked out — log in with it and change the password right away.
    </p>
    <form method="POST" action="{{ route('backup.reset') }}" novalidate
          class="flex flex-wrap items-end gap-2 confirm-submit"
          data-confirm-message="This will PERMANENTLY DELETE ALL DATA in the system — every product, customer, supplier, order, purchase order and user account. This cannot be undone. Are you absolutely sure?">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Type <strong>RESET</strong> to confirm</label>
            <input type="text" name="confirm" required autocomplete="off" placeholder="RESET" class="border rounded px-3 py-2 border-red-300">
        </div>
        <button class="btn btn-red">Reset Entire Database</button>
    </form>
    @error('confirm') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
</div>

<script>
document.getElementById('backup-file-input').addEventListener('change', function () {
    document.getElementById('backup-file-name').textContent = this.files.length ? this.files[0].name : 'No file chosen';
});

document.querySelectorAll('[data-quick-range]').forEach(btn => {
    btn.addEventListener('click', () => {
        const to = new Date();
        let from = new Date();
        if (btn.dataset.quickRange === 'week') {
            from.setDate(to.getDate() - 6);
        } else if (btn.dataset.quickRange === 'month') {
            from = new Date(to.getFullYear(), to.getMonth(), 1);
        }
        const fmt = d => d.toISOString().slice(0, 10);
        document.getElementById('range-from').value = fmt(from);
        document.getElementById('range-to').value = fmt(to);
    });
});
</script>
@endsection
