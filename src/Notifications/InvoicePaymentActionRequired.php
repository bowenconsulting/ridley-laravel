<?php

declare(strict_types=1);

namespace Ridley\Laravel\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class InvoicePaymentActionRequired extends RidleyBillingNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment needs your confirmation')
            ->markdown('ridley::notifications.invoice-payment-action-required', [
                'notifiable' => $notifiable,
                'invoice' => $this->object,
                'invoiceNumber' => $this->object['invoice_number'] ?? null,
                'amount' => $this->amount(),
                'nextRetry' => $this->nextRetry(),
                'actionUrl' => $this->actionUrl(),
                'actionText' => 'Pay invoice',
            ]);
    }
}
