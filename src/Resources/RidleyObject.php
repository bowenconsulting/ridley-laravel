<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;
use LogicException;

/**
 * A Ridley API resource. Attributes are readable as properties or array keys.
 *
 * @property-read string|null $id
 * @property-read string|null $type
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
class RidleyObject implements Arrayable, ArrayAccess, JsonSerializable
{
    /**
     * Map of normalised JSON:API types to resource classes.
     *
     * @var array<string, class-string<RidleyObject>>
     */
    public const array TYPES = [
        'account' => Account::class,
        'source' => Account::class,
        'balance-transaction' => BalanceTransaction::class,
        'customer' => Customer::class,
        'event' => Event::class,
        'invoice' => Invoice::class,
        'invoice-item' => InvoiceItem::class,
        'payment' => Payment::class,
        'payment-method' => PaymentMethod::class,
        'portal-session' => PortalSession::class,
        'price' => Price::class,
        'product' => Product::class,
        'refund' => Refund::class,
        'session' => Session::class,
        'subscription' => Subscription::class,
        'subscription-item' => SubscriptionItem::class,
        'test-clock' => TestClock::class,
        'webhook-endpoint' => WebhookEndpoint::class,
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $attributes;

    /**
     * @var array<string, mixed>
     */
    protected array $links;

    protected ?string $resourceId;

    protected ?string $resourceType;

    /**
     * @param  array<string, mixed>  $data  A JSON:API resource object (`id`, `type`, `attributes`, `links`) or a flat attribute array.
     */
    final public function __construct(protected array $data = [])
    {
        $attributes = $data['attributes'] ?? null;

        if (is_array($attributes)) {
            $this->attributes = $attributes;
            $this->links = is_array($data['links'] ?? null) ? $data['links'] : [];
            $this->resourceId = isset($data['id']) ? (string) $data['id'] : null;
            $this->resourceType = isset($data['type']) ? (string) $data['type'] : null;
        } else {
            $this->attributes = $data;
            $this->links = [];
            $this->resourceId = isset($data['id']) ? (string) $data['id'] : null;
            $this->resourceType = null;
        }
    }

    /**
     * Hydrate the right resource class for a JSON:API resource object.
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<RidleyObject>|null  $fallback  Class used when the type is unknown.
     */
    public static function constructFrom(array $data, ?string $fallback = null): RidleyObject
    {
        $type = isset($data['type']) && is_string($data['type']) ? static::normaliseType($data['type']) : null;

        $class = ($type !== null ? (self::TYPES[$type] ?? null) : null) ?? $fallback ?? self::class;

        return new $class($data);
    }

    /**
     * Normalise `payment-methods`, `payment_methods` and `payment_method` to `payment-method`.
     */
    public static function normaliseType(string $type): string
    {
        return Str::singular(str_replace(['_', '.'], '-', strtolower($type)));
    }

    /**
     * The resource ID.
     */
    public function id(): ?string
    {
        return $this->resourceId;
    }

    /**
     * The resource attributes.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * The resource links (e.g. `self`, `checkout`).
     *
     * @return array<string, mixed>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * The untouched resource object returned by Ridley.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * Read an attribute with dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->toArray(), $key, $default);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'id' => $this->resourceId,
            'type' => $this->resourceType,
            default => $this->attributes[$name] ?? null,
        };
    }

    public function __isset(string $name): bool
    {
        return $this->__get($name) !== null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Ridley resources are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Ridley resources are immutable.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            array_filter(['id' => $this->resourceId, 'type' => $this->resourceType], fn ($value) => $value !== null),
            $this->attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
