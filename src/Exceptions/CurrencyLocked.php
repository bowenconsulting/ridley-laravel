<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

/**
 * The billable's currency is locked (it has subscribed or been invoiced) and a
 * different currency was requested.
 */
class CurrencyLocked extends InvalidRequestException
{
    public string $lockedCurrency = '';

    public string $requestedCurrency = '';

    public static function for(string $lockedCurrency, string $requestedCurrency): static
    {
        $exception = new static(
            "This customer is billed in {$lockedCurrency}; {$requestedCurrency} cannot be used.",
            422,
            ['code' => 'currency_locked', 'param' => 'currency'],
        );

        $exception->lockedCurrency = strtoupper($lockedCurrency);
        $exception->requestedCurrency = strtoupper($requestedCurrency);

        return $exception;
    }
}
