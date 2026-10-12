<?php

declare(strict_types=1);

namespace Ridley\Laravel\Catalogue;

/**
 * The outcome of pulling one Ridley price, or of pushing one price definition.
 */
final readonly class SyncResult
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public const string REPLACED = 'replaced';

    public const string WOULD_CREATE = 'would_create';

    public const string WOULD_REPLACE = 'would_replace';

    /** Pushing: Ridley already has a different price on this lookup key (use --replace). */
    public const string DIFFERS = 'differs';

    /** Pulling: a Ridley price is now in the local catalogue. */
    public const string IMPORTED = 'imported';

    /** Pulling: an existing local price became current again or moved item. */
    public const string UPDATED = 'updated';

    /** Pulling: a local price is no longer sold (archived, lookup key moved or removed). */
    public const string RETIRED = 'retired';

    /** Pulling: a price with this app's lookup key that couldn't be mapped (see $note). */
    public const string SKIPPED = 'skipped';

    public function __construct(
        public string $lookupKey,
        public string $amount,
        public string $action,
        public ?string $ridleyPriceId = null,
        public ?string $note = null,
    ) {}

    public function changed(): bool
    {
        return in_array($this->action, [self::CREATED, self::REPLACED, self::IMPORTED, self::UPDATED, self::RETIRED], true);
    }
}
