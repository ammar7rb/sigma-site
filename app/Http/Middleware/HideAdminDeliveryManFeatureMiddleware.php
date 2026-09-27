<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HideAdminDeliveryManFeatureMiddleware
{
    /**
     * Keep historical delivery-man records intact while preventing their legacy admin surfaces from reopening.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): mixed
    {
        $type = strtolower((string) ($request->route('type') ?? $request->input('type', '')));

        if ($mode === 'message') {
            if ($request->has('delivery_man_id') || $type === 'delivery-man') {
                abort(404);
            }

            return $next($request);
        }

        if ($mode === 'notification') {
            if (in_array($type, ['delivery-man', 'delivery_man', 'deliveryman'], true)) {
                abort(404);
            }

            return $next($request);
        }

        if ($mode === 'email-template') {
            if ($type === 'delivery-man') {
                abort(404);
            }

            return $next($request);
        }

        abort(404);
    }
}
