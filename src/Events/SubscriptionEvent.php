<?php

declare(strict_types=1);

namespace Ridley\Laravel\Events;

use Illuminate\Database\Eloquent\Model;
use Ridley\Laravel\Subscription;

abstract class SubscriptionEvent extends RidleyWebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>|null  $previous
     */
    public function __construct(
        array $payload,
        array $object,
        ?array $previous = null,
        ?Model $billable = null,
        public readonly ?Subscription $subscription = null,
    ) {
        parent::__construct($payload, $object, $previous, $billable);
    }
}
