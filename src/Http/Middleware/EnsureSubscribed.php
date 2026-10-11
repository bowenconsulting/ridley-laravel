<?php

declare(strict_types=1);

namespace Ridley\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ridley\Laravel\Ridley;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ridley.subscribed:{type?}` — requires a valid subscription (or a generic trial for the default type).
 */
class EnsureSubscribed
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $type = 'default'): Response
    {
        $billable = Ridley::resolveBillable($request);

        $allowed = $billable !== null && method_exists($billable, 'subscribed')
            && ($billable->subscribed($type) || ($type === 'default' && $billable->onGenericTrial()));

        return $allowed ? $next($request) : self::deny($request, 'A subscription is required.');
    }

    /**
     * 402 JSON for API requests, otherwise a redirect to `ridley.billing_url`.
     */
    public static function deny(Request $request, string $message): Response
    {
        $billingUrl = config('ridley.billing_url');

        if ($request->expectsJson() || ! is_string($billingUrl) || $billingUrl === '') {
            return response()->json(['message' => $message, 'billing_url' => $billingUrl], 402);
        }

        return redirect()->to($billingUrl)->with('status', $message);
    }
}
