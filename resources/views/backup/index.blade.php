@extends('layouts.app')
@section('title', __('Backup & Restore'))
@section('content')
<h1 class="text-2xl font-bold mb-4">{{ __('Backup & Restore') }}</h1>

<div class="bg-white p-6 rounded-xl shadow-sm w-full mb-6">
    <h2 class="font-semibold text-gray-800 mb-1">{{ __('Full Backup') }}</h2>
    <p class="text-sm text-gray-500 mb-3">
        {{ __('Downloads everything in the system — products, customers, suppliers, users, orders, purchase orders, stock movements and returns — as one file.') }}
    </p>
    <a href="{{ route('backup.download', ['type' => 'full']) }}" class="btn btn-dark">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
        {{ __('Download Full Backup') }}
    </a>
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full mb-6">
    <h2 class="font-semibold text-gray-800 mb-1">{{ __('Backup a Date Range') }}</h2>
    <p class="text-sm text-gray-500 mb-3">
        {{ __('Only sales, purchases, stock movements and returns from the dates you pick are included — products/customers/suppliers/users come along in full too, since the transactions in that range reference them.') }}
    </p>
    <form method="GET" action="{{ route('backup.download') }}" class="flex flex-wrap items-end gap-2">
        <input type="hidden" name="type" value="range">
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('From') }}</label>
            <input type="date" name="from" id="range-from" required class="border rounded px-3">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('To') }}</label>
            <input type="date" name="to" id="range-to" required class="border rounded px-3">
        </div>
        <button type="button" class="btn btn-gray" data-quick-range="week">{{ __('Last 7 Days') }}</button>
        <button type="button" class="btn btn-gray" data-quick-range="month">{{ __('This Month') }}</button>
        <button class="btn btn-dark">{{ __('Download Range Backup') }}</button>
    </form>
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full mb-6">
    <h2 class="font-semibold text-gray-800 mb-1">{{ __('Automatic Daily Backups') }}</h2>
    <p class="text-sm text-gray-500 mb-3">
        {{ __('Every day at 02:00 the system automatically saves a full backup to the server (storage/app/backups) and keeps only the last 7 — older ones are deleted automatically. This only runs if the server has its scheduled-tasks cron entry set up; otherwise no automatic backup is made.') }}
    </p>
    <p class="text-sm text-gray-500 mb-3">
        {{ __('Backups (automatic files and downloads) are encrypted with the app key — keep a copy of the key with the backups, because losing it makes encrypted backups unrecoverable.') }}
    </p>
    <p class="text-sm text-gray-800 mb-3">
        {{ __('Last automatic backup') }}:
        @if(!empty($latestScheduledBackup))
            <strong>{{ $latestScheduledBackup['modified_at']->format('Y-m-d H:i') }}</strong>
            ({{ $latestScheduledBackup['filename'] }})
        @else
            <strong>{{ __('never run yet') }}</strong>
        @endif
    </p>
    @if(!empty($scheduledBackups ?? []))
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1 pr-2">{{ __('File') }}</th>
                    <th class="py-1 pr-2">{{ __('Size') }}</th>
                    <th class="py-1">{{ __('Date') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($scheduledBackups as $scheduledBackup)
                    <tr class="border-t border-gray-100">
                        <td class="py-1 pr-2">{{ $scheduledBackup['filename'] }}</td>
                        <td class="py-1 pr-2">{{ $scheduledBackup['size_human'] }}</td>
                        <td class="py-1">{{ $scheduledBackup['modified_at']->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-sm text-gray-500 mt-3">
            {{ __('These files live on the server and cannot be downloaded from this page — to restore one, copy it from the server\'s storage/app/backups folder and upload it with the Restore form below.') }}
        </p>
    @else
        <p class="text-sm text-gray-500">{{ __('No automatic backups saved yet.') }}</p>
    @endif
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full">
    <h2 class="font-semibold text-gray-800 mb-1">{{ __('Restore from Backup') }}</h2>
    <p class="text-sm text-gray-500 mb-3">
        {{ __('Restoring a') }} <strong>{{ __('Full') }}</strong> {{ __('backup replaces everything currently in the system — you\'ll be logged out and need to sign back in with an account from that backup. Restoring a') }} <strong>date-range</strong> {{ __('backup merges those records in without touching anything outside that range.') }}
    </p>
    <form method="POST" action="{{ route('backup.restore') }}" enctype="multipart/form-data" novalidate
          class="flex flex-wrap items-end gap-2 confirm-submit"
          data-confirm-message="Restore from this backup file? This will overwrite matching records — make sure you have a current backup first if you're not sure.">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Backup file (.json)') }}</label>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-gray" onclick="document.getElementById('backup-file-input').click()">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                    {{ __('Choose File') }}
                </button>
                <span id="backup-file-name" class="text-sm text-gray-500">{{ __('No file chosen') }}</span>
            </div>
            <input type="file" id="backup-file-input" name="backup_file" accept="application/json,.json" required data-label="Backup file" class="hidden">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Your current password') }}</label>
            <input type="password" name="current_password" required autocomplete="current-password" placeholder="{{ __('Current password') }}" class="border rounded px-3 py-2">
        </div>
        <button class="btn btn-solid-yellow">{{ __('Restore Backup') }}</button>
    </form>
    @error('backup_file') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
    @error('current_password') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
</div>

<div class="bg-white p-6 rounded-xl shadow-sm w-full mt-6 border-2 border-red-200">
    <h2 class="font-semibold text-red-700 mb-1">{{ __('Danger Zone — Reset Database') }}</h2>
    <p class="text-sm text-gray-500 mb-3">
        {{ __('Permanently erases') }} <strong>{{ __('everything') }}</strong> {{ __('— products, customers, suppliers, orders, purchase orders, stock movements, payments, stock returns, and every user account. This cannot be undone from inside the app; the only way back is restoring a backup taken beforehand. A single admin account is recreated automatically so the system isn\'t left completely locked out — reset creates a random one-time password shown once, which must be changed at first login.') }}

    </p>
    <form method="POST" action="{{ route('backup.reset') }}" novalidate
          class="flex flex-wrap items-end gap-2 confirm-submit"
          data-confirm-message="{{ __('This will PERMANENTLY DELETE ALL DATA in the system — every product, customer, supplier, order, purchase order and user account. This cannot be undone. Are you absolutely sure?') }}">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Type') }} <strong>{{ __('RESET') }}</strong> {{ __('to confirm') }}</label>
            <input type="text" name="confirm" required autocomplete="off" placeholder="{{ __('RESET') }}" class="border rounded px-3 py-2 border-red-300">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('Your current password') }}</label>
            <input type="password" name="current_password" required autocomplete="current-password" placeholder="{{ __('Current password') }}" class="border rounded px-3 py-2 border-red-300">
        </div>
        <button class="btn btn-red">{{ __('Reset Entire Database') }}</button>
    </form>
    @error('confirm') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
    @error('current_password') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
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
