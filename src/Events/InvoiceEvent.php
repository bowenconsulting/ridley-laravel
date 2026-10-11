<?php

declare(strict_types=1);

namespace Ridley\Laravel\Events;

use Ridley\Laravel\Ridley;

abstract class InvoiceEvent extends RidleyWebhookEvent
{
    public function invoiceId(): string
    {
        return (string) ($this->object['invoice_id'] ?? $this->object['id'] ?? '');
    }

    public function subscriptionId(): ?string
    {
        $id = $this->object['subscription_id'] ?? $this->object['subscription'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * The formatted amount due, e.g. "$19.99".
     */
    public function amountDue(): string
    {
        return Ridley::formatAmount((string) ($this->object['amount_due'] ?? $this->object['total'] ?? '0'), (string) ($this->object['currency'] ?? 'USD'));
    }

    public function paymentUrl(): ?string
    {
        $url = $this->object['payment_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
