<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Enums\SessionKey;
use App\Services\VendorService;
use App\Services\SellerRegistrationVerificationService;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use App\Http\Requests\Vendor\LoginRequest;
use App\Repositories\VendorWalletRepository;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use App\Contracts\Repositories\VendorRepositoryInterface;

class LoginController extends Controller
{
    public function __construct(
        private readonly VendorRepositoryInterface $vendorRepo,
        private readonly VendorService             $vendorService,
        private readonly VendorWalletRepository    $vendorWalletRepo,
        private readonly SellerRegistrationVerificationService $sellerRegistrationVerificationService,

    )
    {
        $this->middleware('guest:seller', ['except' => ['logout']]);
    }

    public function getLoginView(): View
    {
        return view('vendor-views.auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $vendor = $this->vendorRepo->getFirstWhere(['identity' => $request['email']]);
        if (!$vendor) {
            ToastMagic::error(translate('credentials_doesnt_match') . '!');
            return back();
        }
        $passwordCheck = Hash::check($request['password'], $vendor['password']);
        if ($passwordCheck && in_array($vendor['status'], ['pending', 'approved'], true)
            && $this->sellerRegistrationVerificationService->requiresPhoneVerification($vendor)) {
            session()->put(SessionKey::VENDOR_REGISTRATION_REFERENCE, $vendor['registration_reference']);
            $otpResult = $this->sellerRegistrationVerificationService->sendOtp($vendor);
            $otpResult['status']
                ? ToastMagic::success($otpResult['message'])
                : ToastMagic::warning($otpResult['message']);
            return redirect()->route('vendor.auth.registration.verify-phone');
        }
        if ($passwordCheck && $vendor['status'] !== 'approved') {
            ToastMagic::error(translate('Not_approve_yet') . '!');
            return back();
        }
        if ($this->vendorService->isLoginSuccessful($request->email, $request->password, $request->remember)) {
            if ($this->vendorWalletRepo->getFirstWhere(params: ['id' => auth('seller')->id()]) === false) {
                $this->vendorWalletRepo->add($this->vendorService->getInitialWalletData(vendorId: auth('seller')->id()));
            }
            ToastMagic::info(translate('welcome_to_your_dashboard') . '.');
            return redirect()->route('vendor.dashboard.index');
        } else {
            ToastMagic::error(translate('credentials_doesnt_match') . '!');
            return back();
        }
    }

    public function logout(): RedirectResponse
    {
        $this->vendorService->logout();
        ToastMagic::success(translate('logged_out_successfully') . '.');
        return redirect()->route('vendor.auth.login');
    }
}
