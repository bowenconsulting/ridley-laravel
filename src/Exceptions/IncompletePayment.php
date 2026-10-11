<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

use Ridley\Laravel\Resources\Invoice;
use Ridley\Laravel\Subscription;

/**
 * The payment needs extra customer action (3-D Secure) or could not be completed.
 *
 * Redirect the customer to {@see paymentUrl()} so they can finish paying.
 */
class IncompletePayment extends RidleyException
{
    /**
     * The local subscription left incomplete, when the payment belonged to one.
     */
    public ?Subscription $subscription = null;

    /**
     * The invoice awaiting payment, when known.
     */
    public ?Invoice $invoice = null;

    /**
     * Create the exception for a subscription Ridley left `incomplete`.
     */
    public static function forSubscription(Subscription $subscription, ?Invoice $invoice = null): static
    {
        $exception = new static(
            "The payment for subscription [{$subscription->ridley_id}] is incomplete and requires customer action.",
            402,
            ['code' => 'subscription_incomplete'],
        );

        $exception->subscription = $subscription;
        $exception->invoice = $invoice;

        return $exception;
    }

    /**
     * Whether the customer must authenticate the payment (3-D Secure).
     */
    public function requiresAuthentication(): bool
    {
        return $this->errorCode() === 'authentication_required';
    }

    /**
     * A hosted URL where the customer can complete the payment, when available.
     */
    public function paymentUrl(): ?string
    {
        $url = $this->error['meta']['payment_url']
            ?? $this->error['payment_url']
            ?? $this->invoice->payment_url
            ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
