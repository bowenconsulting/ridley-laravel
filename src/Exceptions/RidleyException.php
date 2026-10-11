<?php

declare(strict_types=1);

namespace Ridley\Laravel\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Base exception for every error returned by (or while talking to) the Ridley API.
 */
class RidleyException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $error  The first entry of the JSON:API `errors` array.
     * @param  array<string, mixed>|null  $body  The decoded response body.
     */
    final public function __construct(
        string $message = '',
        protected ?int $status = null,
        protected array $error = [],
        protected ?array $body = null,
        protected ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * Build an exception from a failed API response.
     */
    public static function fromResponse(Response $response): static
    {
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        /** @var array<string, mixed> $error */
        $error = is_array($body['errors'][0] ?? null) ? $body['errors'][0] : [];

        $message = (string) ($error['detail'] ?? $error['title'] ?? "Ridley API request failed with status {$response->status()}.");
        $requestId = $error['request_id'] ?? $response->header('X-Request-Id');

        return new static(
            $message,
            $response->status(),
            $error,
            $body,
            is_string($requestId) && $requestId !== '' ? $requestId : null,
        );
    }

    /**
     * The HTTP status code of the failed response.
     */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * The machine readable Ridley error code, e.g. `card_declined`.
     */
    public function errorCode(): ?string
    {
        $code = $this->error['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * The request parameter that caused the error, when Ridley reports one.
     */
    public function param(): ?string
    {
        $param = $this->error['param'] ?? null;

        return is_string($param) ? $param : null;
    }

    /**
     * The Ridley request ID (useful when contacting support).
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Field level validation messages keyed by parameter name.
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        $errors = $this->error['meta']['errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }

    /**
     * The first JSON:API error object.
     *
     * @return array<string, mixed>
     */
    public function error(): array
    {
        return $this->error;
    }

    /**
     * The decoded response body.
     *
     * @return array<string, mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }
}
