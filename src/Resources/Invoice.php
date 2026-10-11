<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Support\Timestamp;

/**
 * @property-read string|null $invoice_number
 * @property-read string|null $status
 * @property-read string|null $customer
 * @property-read string|null $subscription
 * @property-read string|null $billing_reason
 * @property-read string|null $currency
 * @property-read string|null $subtotal
 * @property-read string|null $tax_amount
 * @property-read string|null $total
 * @property-read string|null $amount_paid
 * @property-read string|null $amount_due
 * @property-read string|null $payment_url
 * @property-read string|null $next_payment_attempt
 * @property-read string|null $due_date
 * @property-read string|null $period_start
 * @property-read string|null $period_end
 * @property-read string|null $created_at
 * @property-read list<mixed>|null $items
 */
class Invoice extends RidleyObject
{
    public function number(): ?string
    {
        return $this->invoice_number;
    }

    public function total(): string
    {
        return $this->formatted('total');
    }

    public function subtotal(): string
    {
        return $this->formatted('subtotal');
    }

    public function tax(): string
    {
        return $this->formatted('tax_amount');
    }

    public function amountDue(): string
    {
        return $this->formatted('amount_due');
    }

    public function amountPaid(): string
    {
        return $this->formatted('amount_paid');
    }

    /**
     * The total as Ridley's decimal string, e.g. "19.99".
     */
    public function rawTotal(): string
    {
        return (string) ($this->attributes['total'] ?? '0.00');
    }

    public function rawAmountDue(): string
    {
        return (string) ($this->attributes['amount_due'] ?? '0.00');
    }

    /**
     * The invoice date: creation date, falling back to the period start/end.
     */
    public function date(): ?CarbonImmutable
    {
        return Timestamp::parse($this->created_at ?? $this->period_start ?? $this->period_end);
    }

    public function dueDate(): ?CarbonImmutable
    {
        return Timestamp::parse($this->due_date);
    }

    public function nextPaymentAttempt(): ?CarbonImmutable
    {
        return Timestamp::parse($this->next_payment_attempt);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isUncollectible(): bool
    {
        return $this->status === 'uncollectible';
    }

    /**
     * The invoice lines.
     *
     * @return Collection<int, InvoiceItem>
     */
    public function lines(): Collection
    {
        return collect($this->items ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => new InvoiceItem($item))
            ->values();
    }

    public function hasProration(): bool
    {
        return $this->lines()->contains(fn (InvoiceItem $item) => (bool) $item->proration);
    }

    /**
     * Whether applying the previewed change would charge the customer immediately.
     */
    public function chargesNow(): bool
    {
        return $this->billing_reason === 'subscription_update';
    }

    protected function formatted(string $attribute): string
    {
        return Ridley::formatAmount((string) ($this->attributes[$attribute] ?? '0'), $this->currency);
    }
}
