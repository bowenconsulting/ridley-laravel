<?php

declare(strict_types=1);

namespace Ridley\Laravel\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Ridley\Laravel\Concerns\ManagesCustomer;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Support\Timestamp;

/**
 * Base class for the package's (optional) dunning mails.
 */
abstract class RidleyBillingNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $object  The webhook `data.object`.
     */
    public function __construct(public readonly array $object) {}

    /**
     * The billable the notice is about; its {@see ManagesCustomer::ridleyBillingUrl()} is the fallback link.
     */
    public ?Model $billable = null;

    public function forBillable(?Model $billable): static
    {
        $this->billable = $billable;

        return $this;
    }

    /**
     * The billable's billing page, else the configured one.
     */
    protected function billingUrl(): ?string
    {
        if ($this->billable !== null && method_exists($this->billable, 'ridleyBillingUrl')) {
            return $this->billable->ridleyBillingUrl();
        }

        $fallback = config('ridley.notifications.billing_url') ?? config('ridley.billing_url');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract public function toMail(object $notifiable): MailMessage;

    /**
     * The formatted amount due, e.g. "$49.00".
     */
    public function amount(): ?string
    {
        $amount = $this->object['amount_due'] ?? $this->object['total'] ?? null;

        if ($amount === null || $amount === '') {
            return null;
        }

        return Ridley::formatAmount((string) $amount, isset($this->object['currency']) ? (string) $this->object['currency'] : null);
    }

    /**
     * When Ridley will next retry the payment, e.g. "May 3, 2026".
     */
    public function nextRetry(): ?string
    {
        return Timestamp::parse($this->object['next_payment_attempt'] ?? null)?->toFormattedDateString();
    }

    /**
     * The invoice's hosted payment page, or the configured billing page.
     */
    public function actionUrl(): ?string
    {
        $url = $this->object['payment_url'] ?? null;

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return $this->billingUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'object' => $this->object,
            'amount' => $this->amount(),
            'next_retry' => $this->nextRetry(),
            'action_url' => $this->actionUrl(),
        ];
    }
}
