<x-mail::message>
# Payment failed

We couldn't take payment{{ $amount ? ' of '.$amount : '' }} for invoice {{ $invoiceNumber ?? '' }}.

@if ($nextRetry)
We'll try again on **{{ $nextRetry }}**. To avoid an interruption, please update your payment method before then.
@else
Please update your payment method to keep your subscription active.
@endif

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
