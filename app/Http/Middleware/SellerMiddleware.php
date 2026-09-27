<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\SellerRegistrationVerificationService;
use Illuminate\Http\Request;

class SellerMiddleware
{
    public function __construct(
        private readonly SellerRegistrationVerificationService $verificationService,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (auth('seller')->check()
            && auth('seller')->user()->status === 'approved'
            && !$this->verificationService->requiresPhoneVerification(auth('seller')->user())) {
            return $next($request);
        }
        auth()->guard('seller')->logout();

        return redirect()->route('vendor.auth.login');
    }
}
