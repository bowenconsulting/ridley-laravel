<?php

declare(strict_types=1);

namespace Ridley\Laravel\Testing;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ridley\Laravel\Ridley;
use Ridley\Laravel\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post signed Ridley webhooks to the package's webhook route from a test case.
 *
 * @mixin TestCase
 */
trait InteractsWithRidleyWebhooks
{
    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>|null  $previous
     * @return TestResponse<Response>
     */
    public function postRidleyWebhook(string $type, array $object, ?array $previous = null, ?string $eventId = null): TestResponse
    {
        $secrets = WebhookSignature::secrets(config('ridley.webhook.secret'));

        if ($secrets === []) {
            config(['ridley.webhook.secret' => $secret = 'whsec_test_'.Str::random(24)]);
        } else {
            $secret = $secrets[0];
        }

        $payload = (string) json_encode(Ridley::webhookPayload($type, $object, $previous, $eventId));

        return $this->call('POST', route('ridley.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', WebhookSignature::HEADER)) => WebhookSignature::sign($payload, $secret),
        ], $payload);
    }
}
