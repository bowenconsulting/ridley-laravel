<?php

declare(strict_types=1);

namespace Ridley\Laravel\Events;

class WebhookHandled
{
    /**
     * @param  array<string, mixed>  $payload  The (normalised) webhook envelope.
     */
    public function __construct(public readonly array $payload) {}
}
