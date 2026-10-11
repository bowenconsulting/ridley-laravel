<?php

declare(strict_types=1);

namespace Ridley\Laravel\Resources;

/**
 * @property-read string|null $event_type
 * @property-read array<string, mixed>|null $data
 */
class Event extends RidleyObject
{
    /**
     * The event type, e.g. `invoice.paid`.
     */
    public function eventType(): ?string
    {
        $type = $this->attributes['type'] ?? $this->attributes['event_type'] ?? null;

        return is_string($type) ? $type : null;
    }
}
