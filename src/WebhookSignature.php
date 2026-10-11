<?php

declare(strict_types=1);

namespace Ridley\Laravel;

use Ridley\Laravel\Exceptions\InvalidSignature;

/**
 * Verifies and creates `X-Webhook-Signature: t={unix},v1={hex}[,v1={hex}…]` headers.
 */
final class WebhookSignature
{
    public const string HEADER = 'X-Webhook-Signature';

    /**
     * @param  string|array<int, string>  $secrets  One secret, several, or a comma-separated list (rotation).
     *
     * @throws InvalidSignature
     */
    public static function verifyHeader(string $payload, ?string $header, string|array $secrets, int $tolerance = 300): void
    {
        $secrets = self::secrets($secrets);

        if ($secrets === []) {
            throw new InvalidSignature('No webhook signing secret is configured.');
        }

        if ($header === null || trim($header) === '') {
            throw new InvalidSignature('Missing '.self::HEADER.' header.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && $value !== null && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new InvalidSignature('Unable to parse the '.self::HEADER.' header.');
        }

        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            throw new InvalidSignature('Webhook timestamp is outside the tolerance zone.');
        }

        foreach ($secrets as $secret) {
            $expected = self::compute($payload, $secret, $timestamp);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return;
                }
            }
        }

        throw new InvalidSignature('No signatures found matching the expected signature for the payload.');
    }

    /**
     * Build a header value for the payload (handy in tests).
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.self::compute($payload, $secret, $timestamp);
    }

    /**
     * @param  string|array<int, string>|null  $secrets
     * @return list<string>
     */
    public static function secrets(string|array|null $secrets): array
    {
        $list = is_array($secrets) ? $secrets : explode(',', (string) $secrets);

        return array_values(array_filter(array_map(fn ($secret) => trim((string) $secret), $list), fn (string $secret) => $secret !== ''));
    }

    private static function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
