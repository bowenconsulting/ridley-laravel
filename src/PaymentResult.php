<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Ridley\Laravel\Resources\Payment;

/**
 * The outcome of `$billable->payOrCheckout()`: a charge of the saved card, or a
 * checkout the customer must complete.
 */
final readonly class PaymentResult
{
    public function __construct(
        private ?Payment $payment = null,
        private ?Checkout $checkout = null,
    ) {}

    public static function charged(Payment $payment): self
    {
        return new self(payment: $payment);
    }

    public static function checkoutRequired(Checkout $checkout): self
    {
        return new self(checkout: $checkout);
    }

    public function payment(): ?Payment
    {
        return $this->payment;
    }

    public function checkout(): ?Checkout
    {
        return $this->checkout;
    }

    public function requiresCheckout(): bool
    {
        return $this->checkout !== null;
    }

    public function redirectUrl(): ?string
    {
        return $this->checkout?->url();
    }

    /**
     * Whether the saved card was charged successfully (false while a checkout is pending).
     */
    public function succeeded(): bool
    {
        return $this->payment !== null && $this->payment->isSucceeded();
    }
}
