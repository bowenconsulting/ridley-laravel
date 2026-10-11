<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * Maps a failed Ridley response onto the most specific exception class.
 */
final class ExceptionFactory
{
    /**
     * Error codes that mean a card could not be charged.
     *
     * @var list<string>
     */
    public const array CARD_CODES = [
        'card_declined',
        'insufficient_funds',
        'payment_method_unusable',
        'charge_not_attempted',
        'no_payment_method',
    ];

    public static function fromResponse(Response $response): RidleyException
    {
        $code = $response->json('errors.0.code');
        $status = $response->status();

        $class = match (true) {
            $code === 'authentication_required' => IncompletePayment::class,
            in_array($code, self::CARD_CODES, true) => CardException::class,
            $code === 'plan_upgrade_required' => PermissionException::class,
            is_string($code) && str_starts_with($code, 'idempotency_') => IdempotencyException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => CardException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => IdempotencyException::class,
            $status === 400, $status === 422 => InvalidRequestException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ApiException::class,
            default => RidleyException::class,
        };

        return $class::fromResponse($response);
    }
}
