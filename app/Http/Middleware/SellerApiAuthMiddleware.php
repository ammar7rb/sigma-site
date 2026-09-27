<?php

namespace App\Http\Middleware;

use App\Models\Seller;
use App\Services\SellerRegistrationVerificationService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SellerApiAuthMiddleware
{
    public function __construct(
        private readonly SellerRegistrationVerificationService $verificationService,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $token = explode(' ', $request->header('authorization'));
        if (count($token) > 1 && strlen($token[1]) > 30) {
            $seller = Seller::where(['auth_token' => $token['1']])->first();
            if (isset($seller)) {
                if ($seller->status !== 'approved') {
                    return response()->json([
                        'errors' => [[
                            'code' => 'seller_account_not_approved',
                            'message' => translate('please_wait_for_admin_approval'),
                        ]],
                        'eligibility' => $this->verificationService->getEligibility($seller),
                    ], 403);
                }

                if ($this->verificationService->requiresPhoneVerification($seller)) {
                    return response()->json([
                        'errors' => [[
                            'code' => 'seller_phone_verification_required',
                            'message' => translate('Please_verify_your_phone'),
                        ]],
                        'registration_reference' => $seller->registration_reference,
                        'eligibility' => $this->verificationService->getEligibility($seller),
                    ], 403);
                }

                $request['seller'] = $seller;
                return $next($request);
            }
        }

        return response()->json([
            'auth-001' => translate('Your existing session token does not authorize you any more')
        ], 401);
    }
}
