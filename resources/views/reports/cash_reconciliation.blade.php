@extends('layouts.app')
@section('title', __('Cash Reconciliation'))
@section('content')
<div class="flex justify-between items-center mb-4">
    <h1 class="text-2xl font-bold">{{ __('Daily Cash Reconciliation') }}</h1>
    @include('reports._export_pdf')
    <a href="{{ route('reports.export.cash-reconciliation', request()->only(['date', 'counted_cash'])) }}" class="btn btn-solid-green print:hidden">{{ __('Export CSV') }}</a>
</div>
<form method="GET" class="bg-white p-6 rounded-xl shadow-sm max-w-md space-y-4">
    <div>
        <label class="block text-sm text-gray-600">{{ __('Date') }}</label>
        <input type="date" name="date" value="{{ $date }}" class="w-full border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-gray-600">{{ __('Recorded Cash Sales (system)') }}</label>
        <input type="text" value="{{ number_format($recordedCash, 2) }}" disabled class="w-full border rounded px-3 py-2 bg-gray-100">
    </div>
    <div>
        <label class="block text-sm text-gray-600">{{ __('Physical Cash Counted') }}</label>
        <input type="number" step="0.01" name="counted_cash" value="{{ $countedCash }}" class="w-full border rounded px-3 py-2">
    </div>
    <button class="bg-gray-900 text-white px-4 py-2 rounded print:hidden">{{ __('Check') }}</button>

    @if(!is_null($mismatch))
        <div class="mt-3 p-3 rounded {{ abs($mismatch) < 0.01 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
            @if(abs($mismatch) < 0.01)
                ✅ Matches perfectly.
            @else
                ⚠ Mismatch: {{ $mismatch > 0 ? 'Excess' : 'Shortage' }} of {{ number_format(abs($mismatch), 2) }}
            @endif
        </div>
    @endif
</form>
@endsection
