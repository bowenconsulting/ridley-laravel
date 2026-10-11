<?php

declare(strict_types=1);

namespace Ridley\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ridley\Laravel\Exceptions\InvalidSignature;
use Ridley\Laravel\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Rejects webhooks without a valid signature. A no-op while no secret is configured.
 */
class VerifyRidleySignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = WebhookSignature::secrets(config('ridley.webhook.secret'));

        if ($secrets === []) {
            return $next($request);
        }

        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $request->header(WebhookSignature::HEADER),
                $secrets,
                (int) config('ridley.webhook.tolerance', 300),
            );
        } catch (InvalidSignature $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $next($request);
    }
}
