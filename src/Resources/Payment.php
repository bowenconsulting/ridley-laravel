<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

use Ridley\Laravel\Ridley;
use Ridley\Laravel\Support\Money;

/**
 * @property-read string|null $reference
 * @property-read string|null $amount
 * @property-read string|null $currency
 * @property-read string|null $status
 * @property-read bool|null $paid
 * @property-read string|null $card_brand
 * @property-read string|null $card_last
 * @property-read string|null $failure_description
 * @property-read string|null $invoice
 * @property-read array<string, mixed>|null $metadata
 */
class Payment extends RidleyObject
{
    /**
     * Statuses that mean the money was collected.
     *
     * @var list<string>
     */
    public const array SUCCEEDED_STATUSES = ['paid', 'completed', 'succeeded', 'partially_refunded', 'refunded'];

    public function isSucceeded(): bool
    {
        return $this->paid === true || in_array(strtolower((string) $this->status), self::SUCCEEDED_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->isSucceeded();
    }

    public function isFailed(): bool
    {
        return strtolower((string) $this->status) === 'failed';
    }

    public function isPending(): bool
    {
        return in_array(strtolower((string) $this->status), ['pending', 'processing', 'authorized'], true);
    }

    /**
     * The formatted amount, e.g. "$19.99".
     */
    public function amount(): string
    {
        return Ridley::formatAmount((string) ($this->attributes['amount'] ?? '0'), $this->currency);
    }

    public function rawAmount(): string
    {
        return (string) ($this->attributes['amount'] ?? '0.00');
    }

    public function amountInMinorUnits(): int
    {
        return Money::toMinor($this->rawAmount());
    }
}
