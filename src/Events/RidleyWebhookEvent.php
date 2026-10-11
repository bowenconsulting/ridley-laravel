<?php

declare(strict_types=1);

namespace Ridley\Laravel\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for typed webhook events.
 */
abstract class RidleyWebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  The webhook envelope.
     * @param  array<string, mixed>  $object  `data.object`.
     * @param  array<string, mixed>|null  $previous  `data.previous_attributes`.
     */
    public function __construct(
        public readonly array $payload,
        public readonly array $object,
        public readonly ?array $previous = null,
        public readonly ?Model $billable = null,
    ) {}

    public function eventId(): ?string
    {
        return isset($this->payload['id']) ? (string) $this->payload['id'] : null;
    }

    public function eventType(): string
    {
        return (string) ($this->payload['type'] ?? '');
    }

    /**
     * The object's metadata.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return is_array($this->object['metadata'] ?? null) ? $this->object['metadata'] : [];
    }
}
