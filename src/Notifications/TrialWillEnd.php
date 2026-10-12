<?php

declare(strict_types=1);

namespace Ridley\Laravel\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Ridley\Laravel\Subscription;
use Ridley\Laravel\Support\Timestamp;

class TrialWillEnd extends RidleyBillingNotification
{
    /**
     * @param  array<string, mixed>  $object  The subscription webhook object.
     */
    public function __construct(array $object, public readonly ?Subscription $subscription = null)
    {
        parent::__construct($object);
    }

    public function trialEndsAt(): ?string
    {
        return Timestamp::parse($this->object['trial_end'] ?? null)?->toFormattedDateString();
    }

    public function actionUrl(): ?string
    {
        return $this->billingUrl();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your trial is ending soon')
            ->markdown('ridley::notifications.trial-will-end', [
                'notifiable' => $notifiable,
                'subscription' => $this->subscription,
                'trialEndsAt' => $this->trialEndsAt(),
                'actionUrl' => $this->actionUrl(),
                'actionText' => 'Update payment method',
            ]);
    }
}
