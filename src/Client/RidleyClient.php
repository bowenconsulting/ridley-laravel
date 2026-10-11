<?php

declare(strict_types=1);

namespace Ridley\Laravel\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Ridley\Laravel\Client\Services\BalanceTransactionService;
use Ridley\Laravel\Client\Services\ChargeService;
use Ridley\Laravel\Client\Services\CustomerService;
use Ridley\Laravel\Client\Services\EventService;
use Ridley\Laravel\Client\Services\InvoiceItemService;
use Ridley\Laravel\Client\Services\InvoiceService;
use Ridley\Laravel\Client\Services\PaymentMethodService;
use Ridley\Laravel\Client\Services\PaymentService;
use Ridley\Laravel\Client\Services\PortalSessionService;
use Ridley\Laravel\Client\Services\PriceService;
use Ridley\Laravel\Client\Services\ProductService;
use Ridley\Laravel\Client\Services\SessionService;
use Ridley\Laravel\Client\Services\SubscriptionItemService;
use Ridley\Laravel\Client\Services\SubscriptionService;
use Ridley\Laravel\Client\Services\TestClockService;
use Ridley\Laravel\Client\Services\WebhookEndpointService;
use Ridley\Laravel\Exceptions\ApiConnectionException;
use Ridley\Laravel\Exceptions\AuthenticationException;
use Ridley\Laravel\Exceptions\ExceptionFactory;
use Ridley\Laravel\Resources\Account;
use Ridley\Laravel\Resources\RidleyObject;

/**
 * HTTP client for the Ridley API.
 *
 * Holds no per-request state, so a fresh instance is resolved from the container each time.
 */
class RidleyClient
{
    public const string VERSION = '1.0.0';

    /**
     * @param  array{timeout?: int|float, connect_timeout?: int|float, retries?: int, retry_delay_ms?: int}  $http
     */
    public function __construct(
        protected ?string $key,
        protected string $baseUrl = 'https://api.ridleypay.com/v1',
        protected array $http = [],
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /**
     * Build a client from the `ridley` config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): static
    {
        /** @var array{timeout?: int|float, connect_timeout?: int|float, retries?: int, retry_delay_ms?: int} $http */
        $http = is_array($config['http'] ?? null) ? $config['http'] : [];

        return new static(
            isset($config['key']) ? (string) $config['key'] : null,
            (string) ($config['base_url'] ?? 'https://api.ridleypay.com/v1'),
            $http,
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function customers(): CustomerService
    {
        return new CustomerService($this);
    }

    public function paymentMethods(): PaymentMethodService
    {
        return new PaymentMethodService($this);
    }

    public function products(): ProductService
    {
        return new ProductService($this);
    }

    public function prices(): PriceService
    {
        return new PriceService($this);
    }

    public function subscriptions(): SubscriptionService
    {
        return new SubscriptionService($this);
    }

    public function subscriptionItems(): SubscriptionItemService
    {
        return new SubscriptionItemService($this);
    }

    public function invoices(): InvoiceService
    {
        return new InvoiceService($this);
    }

    public function invoiceItems(): InvoiceItemService
    {
        return new InvoiceItemService($this);
    }

    public function sessions(): SessionService
    {
        return new SessionService($this);
    }

    public function charges(): ChargeService
    {
        return new ChargeService($this);
    }

    public function payments(): PaymentService
    {
        return new PaymentService($this);
    }

    public function portalSessions(): PortalSessionService
    {
        return new PortalSessionService($this);
    }

    public function balanceTransactions(): BalanceTransactionService
    {
        return new BalanceTransactionService($this);
    }

    public function testClocks(): TestClockService
    {
        return new TestClockService($this);
    }

    public function webhookEndpoints(): WebhookEndpointService
    {
        return new WebhookEndpointService($this);
    }

    public function events(): EventService
    {
        return new EventService($this);
    }

    /**
     * The account behind the API key.
     */
    public function me(): Account
    {
        /** @var Account */
        return RidleyObject::constructFrom($this->request('GET', 'me')['data'] ?? [], Account::class);
    }

    /**
     * Send a request and return the decoded JSON body.
     *
     * GET and DELETE parameters are sent as a query string (nested arrays use brackets);
     * POST and PATCH parameters are sent as a JSON body.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null, headers?: array<string, string>}  $opts
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $params = [], array $opts = []): array
    {
        $json = $this->send($method, $path, $params, $opts)->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Send a request and return the raw response body (e.g. a PDF).
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null, headers?: array<string, string>}  $opts
     */
    public function requestRaw(string $method, string $path, array $params = [], array $opts = []): string
    {
        return $this->send($method, $path, $params, $opts)->body();
    }

    /**
     * Send a request, retrying 429s, 5xx responses and connection failures.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string|null, headers?: array<string, string>}  $opts
     */
    public function send(string $method, string $path, array $params = [], array $opts = []): Response
    {
        if ($this->key === null || $this->key === '') {
            throw new AuthenticationException('No Ridley API key is configured. Set RIDLEY_SECRET in your environment.', 401);
        }

        $method = strtoupper($method);
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $sendsQuery = in_array($method, ['GET', 'HEAD', 'DELETE'], true);

        if ($sendsQuery && $params !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').$this->encodeQuery($params);
        }

        $headers = $opts['headers'] ?? [];

        if ($method !== 'GET' && $method !== 'HEAD') {
            $headers['Idempotency-Key'] = $opts['idempotency_key'] ?? (string) Str::uuid();
        }

        $retries = max(0, (int) ($this->http['retries'] ?? 2));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $request = $this->pendingRequest()->withHeaders($headers);

                $response = $sendsQuery
                    ? $request->send($method, $url)
                    : $request->send($method, $url, ['json' => $params === [] ? (object) [] : $params]);
            } catch (ConnectionException $exception) {
                if ($attempt <= $retries) {
                    $this->sleep($attempt);

                    continue;
                }

                throw new ApiConnectionException("Could not connect to Ridley: {$exception->getMessage()}", null, [], null, null, $exception);
            }

            if ($response->successful()) {
                return $response;
            }

            if ($attempt <= $retries && $this->shouldRetry($response)) {
                $this->sleep($attempt, $response);

                continue;
            }

            throw ExceptionFactory::fromResponse($response);
        }
    }

    /**
     * Encode query parameters with bracketed nested keys (`filter[customer]=cus_1`).
     *
     * @param  array<string, mixed>  $params
     */
    public function encodeQuery(array $params): string
    {
        $params = $this->normaliseQueryValues($params);

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    protected function pendingRequest(): PendingRequest
    {
        return Http::withToken((string) $this->key)
            ->acceptJson()
            ->withUserAgent('ridley-laravel/'.self::VERSION)
            ->timeout((float) ($this->http['timeout'] ?? 30))
            ->connectTimeout((float) ($this->http['connect_timeout'] ?? 10));
    }

    protected function shouldRetry(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    protected function sleep(int $attempt, ?Response $response = null): void
    {
        $retryAfter = $response?->header('Retry-After');

        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            Sleep::for((int) ceil((float) $retryAfter * 1000))->milliseconds();

            return;
        }

        $delay = (int) ($this->http['retry_delay_ms'] ?? 500);

        Sleep::for($delay * (2 ** ($attempt - 1)))->milliseconds();
    }

    /**
     * Booleans become `true`/`false` strings so Ridley's boolean validation accepts them.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     */
    protected function normaliseQueryValues(array $params): array
    {
        foreach ($params as $key => $value) {
            $params[$key] = match (true) {
                is_array($value) => $this->normaliseQueryValues($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => $value,
            };
        }

        return $params;
    }
}
