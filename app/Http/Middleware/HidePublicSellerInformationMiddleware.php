<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HidePublicSellerInformationMiddleware
{
    /**
     * Prevent customer-facing seller pages and seller chat from being reopened by a direct URL.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): mixed
    {
        if ($mode === 'vendor-chat') {
            if ($request->route('type') !== 'vendor') {
                return $next($request);
            }

            abort(404);
        }

        if ($mode === 'vendor-message') {
            if (!$request->has('vendor_id') || (int) $request->input('vendor_id') === 0) {
                return $next($request);
            }

            abort(404);
        }

        abort(404);
    }
}
