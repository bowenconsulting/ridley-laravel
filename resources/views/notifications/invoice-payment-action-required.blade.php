<x-mail::message>
# Confirm your payment

Your bank needs you to confirm the payment{{ $amount ? ' of '.$amount : '' }} for invoice {{ $invoiceNumber ?? '' }}.

@if ($nextRetry)
If it isn't confirmed, we'll try again on **{{ $nextRetry }}**.
@endif

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
