<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HideVendorLegacyFeaturesMiddleware
{
    /**
     * Keep deprecated vendor-only screens unreachable without deleting legacy records or controllers.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        abort(404);
    }
}
