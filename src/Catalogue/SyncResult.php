<?php

declare(strict_types=1);

namespace Ridley\Laravel\Catalogue;

/**
 * The outcome of syncing one price definition.
 */
final readonly class SyncResult
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public const string REPLACED = 'replaced';

    public const string WOULD_CREATE = 'would_create';

    public const string WOULD_REPLACE = 'would_replace';

    public function __construct(
        public string $lookupKey,
        public string $amount,
        public string $action,
        public ?string $ridleyPriceId = null,
    ) {}

    public function changed(): bool
    {
        return $this->action !== self::UNCHANGED;
    }
}
