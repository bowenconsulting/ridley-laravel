<?php

declare(strict_types=1);

namespace Ridley\Laravel\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class InvoicePaymentFailed extends RidleyBillingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment failed')
            ->markdown('ridley::notifications.invoice-payment-failed', [
                'notifiable' => $notifiable,
                'invoice' => $this->object,
                'invoiceNumber' => $this->object['invoice_number'] ?? null,
                'amount' => $this->amount(),
                'nextRetry' => $this->nextRetry(),
                'actionUrl' => $this->actionUrl(),
                'actionText' => 'Update payment method',
            ]);
    }
}
