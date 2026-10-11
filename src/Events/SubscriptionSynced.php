<?php

declare(strict_types=1);

namespace Ridley\Laravel\Events;

use Illuminate\Database\Eloquent\Model;
use Ridley\Laravel\Subscription;

/**
 * The local subscription mirror was written — after an API call made by the
 * package (`api`) or a webhook (`webhook`). Listen to this one event to keep
 * entitlements in step with billing.
 */
class SubscriptionSynced
{
    public const string SOURCE_API = 'api';

    public const string SOURCE_WEBHOOK = 'webhook';

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Model $billable,
        public readonly string $source,
    ) {}
}
