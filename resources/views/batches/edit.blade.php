@extends('layouts.app')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">{{ __('Edit Batch') }}</h1>
    <a href="{{ route('batches.index') }}" class="underline text-sm">{{ __('Back') }}</a>
</div>

<div class="bg-white rounded-xl shadow-sm p-6 max-w-xl">
    <p class="text-sm text-gray-500 mb-4">{{ __('Product') }}: <span class="font-semibold text-gray-800">{{ $batch->product->name ?? '—' }}</span></p>
    <form method="POST" action="{{ route('batches.update', $batch) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <div>
            <label class="block text-sm font-medium mb-1">{{ __('Batch No') }}</label>
            <input type="text" name="batch_no" value="{{ old('batch_no', $batch->batch_no) }}" maxlength="255" required class="border rounded-lg px-3 py-2 w-full">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">{{ __('Expiry Date') }}</label>
            <input type="date" name="expiry_date" value="{{ old('expiry_date', $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : '') }}" class="border rounded-lg px-3 py-2">
            <p class="text-xs text-gray-400 mt-1">{{ __('Leave blank for no expiry date. The batch will then not appear in the expiring list.') }}</p>
        </div>
        <p class="text-xs text-gray-400">{{ __('Only batch number and expiry date can be changed here. Quantity and remaining stock are calculated from receives, sales and returns and cannot be edited by hand.') }}</p>
        <button type="submit" class="bg-slate-900 text-white px-5 py-2 rounded-lg">{{ __('Save') }}</button>
    </form>
</div>
@endsection
