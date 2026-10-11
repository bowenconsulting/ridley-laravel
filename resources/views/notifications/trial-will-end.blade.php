<x-mail::message>
# Your trial is ending soon

@if ($trialEndsAt)
Your free trial ends on **{{ $trialEndsAt }}**.
@else
Your free trial is ending soon.
@endif
Make sure a payment method is on file so your subscription continues without interruption.

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
