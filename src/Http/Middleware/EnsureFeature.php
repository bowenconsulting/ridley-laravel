<?php

declare(strict_types=1);

namespace Ridley\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ridley\Laravel\Ridley;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ridley.feature:{feature}` — requires a catalogue feature (see the optional entitlements).
 */
class EnsureFeature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $billable = Ridley::resolveBillable($request);

        if ($billable !== null && method_exists($billable, 'hasRidleyFeature') && $billable->hasRidleyFeature($feature)) {
            return $next($request);
        }

        return EnsureSubscribed::deny($request, "Your plan does not include [{$feature}].");
    }
}
