<?php

declare(strict_types=1);

namespace Ridley\Laravel\Events;

use Ridley\Laravel\Support\Money;

abstract class PaymentEvent extends RidleyWebhookEvent
{
    /**
     * The `purpose` given to charge() / checkoutCharge(), if any.
     */
    public function purpose(): ?string
    {
        $purpose = $this->metadata()['purpose'] ?? null;

        return is_string($purpose) && $purpose !== '' ? $purpose : null;
    }

    public function paymentId(): string
    {
        return (string) ($this->object['payment_id'] ?? $this->object['id'] ?? '');
    }

    public function amountInMinorUnits(): int
    {
        return Money::toMinor($this->object['amount'] ?? 0);
    }

    public function currency(): string
    {
        return strtoupper((string) ($this->object['currency'] ?? ''));
    }

    public function reference(): ?string
    {
        return isset($this->object['reference']) ? (string) $this->object['reference'] : null;
    }
}
