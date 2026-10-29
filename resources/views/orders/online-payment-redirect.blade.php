<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Redirecting to') }} {{ ucfirst($gateway) }} — {{ $order->order_number }}</title>
    <style>body{font-family:system-ui,sans-serif;text-align:center;padding:3rem 1rem;color:#1e293b}button{background:#15803d;color:#fff;border:0;border-radius:.5rem;padding:.6rem 1.4rem;font-size:1rem;cursor:pointer}.warn{color:#b45309}</style>
</head>
<body>
    <h1>{{ __('Redirecting to') }} {{ ucfirst($gateway) }}…</h1>
    <p>{{ __('Order') }} {{ $order->order_number }} {{ __('— Amount') }}: {{ number_format($payment->amount, 2) }} PKR</p>
    @if(!$is_live)
        <p class="warn"><strong>{{ __('Test mode') }}:</strong> {{ __('this payment goes to the') }} {{ ucfirst($gateway) }} {{ __('sandbox. No real money will move.') }}</p>
    @endif
    <p>{{ __('If you are not redirected automatically, press the button below.') }}</p>
    {{-- Auto-submitting hosted-checkout form. No card/wallet detail is
         collected here; the customer pays on the gateway's own page. --}}
    <form id="gateway-form" method="POST" action="{{ $action }}">
        @foreach($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <button type="submit">{{ __('Continue to') }} {{ ucfirst($gateway) }}</button>
    </form>
    <script>document.getElementById('gateway-form').submit();</script>
</body>
</html>
