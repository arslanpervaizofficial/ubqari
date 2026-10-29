@extends('layouts.app')
@section('title', __('Audit Log'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Audit Log') }}</h1>
</div>

<p class="text-sm text-gray-500 mb-4">{{ __('Every change to products, customers, suppliers, orders, returns, expenses, cash records and users — plus every sign-in and sign-out. Rows are written automatically and can never be edited from here.') }}</p>

{{-- Filters: same plain GET form + .btn pattern as the other index pages. --}}
<form method="GET" action="{{ route('audit-logs.index') }}" class="flex flex-wrap gap-2 mb-4">
    <select name="user" class="border rounded px-3 py-2">
        <option value="">{{ __('All users') }}</option>
        @foreach($users as $u)
            <option value="{{ $u->id }}" {{ (string) $filters['user'] === (string) $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
        @endforeach
        @if($filters['user'] && !$users->contains('id', (int) $filters['user']))
            <option value="{{ $filters['user'] }}" selected>{{ __('User #') }}{{ $filters['user'] }}</option>
        @endif
    </select>
    <select name="model" class="border rounded px-3 py-2">
        <option value="">{{ __('All records') }}</option>
        @foreach($models as $m)
            <option value="{{ $m }}" {{ $filters['model'] === $m ? 'selected' : '' }}>{{ $m }}</option>
        @endforeach
    </select>
    <select name="action" class="border rounded px-3 py-2">
        <option value="">{{ __('All actions') }}</option>
        @foreach($actions as $a)
            <option value="{{ $a }}" {{ $filters['action'] === $a ? 'selected' : '' }}>{{ ucfirst($a) }}</option>
        @endforeach
    </select>
    <input type="date" name="from" value="{{ $filters['from'] }}" class="border rounded px-3 py-2">
    <input type="date" name="to" value="{{ $filters['to'] }}" class="border rounded px-3 py-2">
    <button class="btn btn-gray">{{ __('Filter') }}</button>
    <a href="{{ route('audit-logs.index') }}" class="btn btn-gray">{{ __('Clear') }}</a>
</form>

@php
    // One-line rendering for a stored value: null reads as (empty),
    // arrays as JSON, booleans as yes/no, everything truncated so a
    // long note can never blow out the table row.
    $fmt = function ($value) {
        if ($value === null) return '(empty)';
        if (is_bool($value)) return $value ? 'yes' : 'no';
        if (is_array($value)) $value = json_encode($value);
        return \Illuminate\Support\Str::limit((string) $value, 60);
    };
    // Badge colours per action, in the app's existing pill style.
    $badge = [
        'created' => 'bg-green-100 text-green-700',
        'updated' => 'bg-blue-100 text-blue-700',
        'deleted' => 'bg-red-100 text-red-700',
        'restored' => 'bg-purple-100 text-purple-700',
        'login' => 'bg-gray-100 text-gray-700',
        'logout' => 'bg-gray-100 text-gray-700',
    ];
@endphp

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
<table class="w-full text-sm">
    <thead class="bg-gray-50 text-left"><tr>
        <th class="p-3">{{ __('Date') }}</th><th class="p-3">{{ __('User') }}</th><th class="p-3">{{ __('Record') }}</th><th class="p-3">{{ __('Action') }}</th><th class="p-3">{{ __('IP') }}</th><th class="p-3">{{ __('Changed Fields') }}</th>
    </tr></thead>
    <tbody>
    @forelse($logs as $log)
        <tr class="border-t align-top">
            <td class="p-3 whitespace-nowrap">{{ $log->created_at ? $log->created_at->format('Y-m-d H:i') : '—' }}</td>
            <td class="p-3 whitespace-nowrap">{{ $log->user->name ?? ($log->user_id ? __('User #') . $log->user_id . __(' (deleted)') : '—') }}</td>
            <td class="p-3 whitespace-nowrap">{{ $log->model }} #{{ $log->model_id ?? '—' }}</td>
            <td class="p-3 whitespace-nowrap">
                <span class="text-xs px-2 py-1 rounded-full {{ $badge[$log->action] ?? 'bg-gray-100 text-gray-700' }}">{{ ucfirst($log->action) }}</span>
            </td>
            <td class="p-3 whitespace-nowrap">{{ $log->ip ?? '—' }}</td>
            <td class="p-3 text-gray-600">
                @if($log->action === 'login')
                    Signed in
                @elseif($log->action === 'logout')
                    Signed out
                @elseif($log->action === 'updated')
                    @php $keys = array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))); @endphp
                    @foreach($keys as $key)
                        <div><span class="font-medium text-gray-800">{{ $key }}:</span> {{ $fmt(($log->old_values ?? [])[$key] ?? null) }} &rarr; {{ $fmt(($log->new_values ?? [])[$key] ?? null) }}</div>
                    @endforeach
                @elseif($log->action === 'created' || $log->action === 'restored')
                    @php $vals = $log->new_values ?? []; $shown = array_slice($vals, 0, 8, true); @endphp
                    @foreach($shown as $key => $value)
                        <div><span class="font-medium text-gray-800">{{ $key }}:</span> {{ $fmt($value) }}</div>
                    @endforeach
                    @if(count($vals) > 8)
                        <div class="text-gray-400">… +{{ count($vals) - 8 }} {{ __('more fields') }}</div>
                    @endif
                @elseif($log->action === 'deleted')
                    @php $vals = $log->old_values ?? []; $shown = array_slice($vals, 0, 8, true); @endphp
                    @foreach($shown as $key => $value)
                        <div><span class="font-medium text-gray-800">{{ $key }}:</span> {{ $fmt($value) }}</div>
                    @endforeach
                    @if(count($vals) > 8)
                        <div class="text-gray-400">… +{{ count($vals) - 8 }} {{ __('more fields') }}</div>
                    @endif
                @else
                    —
                @endif
            </td>
        </tr>
    @empty
        <tr><td class="p-3 text-gray-400" colspan="6">{{ __('No audit entries match these filters.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
