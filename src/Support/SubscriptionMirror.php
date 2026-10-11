<?php

declare(strict_types=1);

namespace Ridley\Laravel\Support;

use Illuminate\Database\Eloquent\Model;
use Ridley\Laravel\Events\SubscriptionSynced;
use Ridley\Laravel\Resources\RidleyObject;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\Subscription;

/**
 * Finds or creates the local mirror of a Ridley subscription and syncs it.
 */
final class SubscriptionMirror
{
    /**
     * @param  RidleyObject|array<string, mixed>  $subscription  API resource or webhook object.
     * @param  Model|null  $billable  The owner; resolved from customer/metadata when null.
     */
    public static function sync(RidleyObject|array $subscription, ?Model $billable = null, string $source = SubscriptionSynced::SOURCE_API, bool $createIfMissing = true): ?Subscription
    {
        $object = $subscription instanceof RidleyObject ? $subscription->toArray() : $subscription;
        $ridleyId = $object['subscription_id'] ?? $object['id'] ?? null;

        if (! is_string($ridleyId) || $ridleyId === '') {
            return null;
        }

        $model = Ridley::subscriptionModel();

        /** @var Subscription|null $local */
        $local = $model::query()->where('ridley_id', $ridleyId)->first();

        if ($local === null) {
            $billable ??= $createIfMissing ? self::billable($object) : null;

            if ($billable === null) {
                return null;
            }

            $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

            $local = new $model;
            $local->forceFill([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'type' => is_string($metadata['type'] ?? null) && $metadata['type'] !== '' ? $metadata['type'] : 'default',
                'ridley_id' => $ridleyId,
                'ridley_status' => (string) ($object['status'] ?? Subscription::STATUS_INCOMPLETE),
            ]);
        }

        return $local->syncFromRidley($object, $source);
    }

    /**
     * Resolve the billable from `customer` / `customer_id`, falling back to the
     * `billable_type` / `billable_id` metadata the package writes.
     *
     * @param  array<string, mixed>  $object
     */
    public static function billable(array $object): ?Model
    {
        $customerId = $object['customer_id'] ?? $object['customer'] ?? null;

        if (is_array($customerId)) {
            $customerId = $customerId['id'] ?? null;
        }

        if (is_string($customerId) && ($billable = Ridley::findBillable($customerId)) !== null) {
            return $billable;
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        return Ridley::findBillableByMorph(
            isset($metadata['billable_type']) ? (string) $metadata['billable_type'] : null,
            isset($metadata['billable_id']) ? (string) $metadata['billable_id'] : null,
        );
    }
}
