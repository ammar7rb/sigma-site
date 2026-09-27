<?php
namespace App\Http\Controllers\Admin\Settings;
use App\Http\Controllers\Controller;
use App\Services\SellerActivationService;
use App\Services\SellerRegistrationVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
class SellerActivationVerificationController extends Controller {
 public function configuration(SellerRegistrationVerificationService $service, SellerActivationService $activationService): View { return view('admin-views.business-settings.seller-activation-verification', ['mode'=>$service->verificationMode(), 'otpSettings'=>$service->otpSettings(), 'automaticMessage'=>$activationService->automaticMessage()]); }
 public function save(Request $request, SellerRegistrationVerificationService $service, SellerActivationService $activationService): RedirectResponse { $data=$request->validate(['mode'=>'required|in:none,otp_only,support_ticket_only,otp_and_support_ticket','otp_ttl_minutes'=>'required|integer|min:1|max:60','otp_max_attempts'=>'required|integer|min:1|max:20','otp_resend_seconds'=>'required|integer|min:10|max:3600','automatic_message'=>'required|string|max:1000']); $service->setVerificationMode($data['mode']); $service->setOtpSettings($data['otp_ttl_minutes'],$data['otp_max_attempts'],$data['otp_resend_seconds']); $activationService->setAutomaticMessage($data['automatic_message']); clearWebConfigCacheKeys(); return back()->with('success','تم الحفظ.'); }
 public function index(SellerRegistrationVerificationService $service): View { return view('admin-views.business-settings.seller-activation-verification', ['mode'=>$service->verificationMode()]); }
 public function update(Request $request, SellerRegistrationVerificationService $service): RedirectResponse { $request->validate(['mode'=>'required|in:none,otp_only,support_ticket_only,otp_and_support_ticket']); $service->setVerificationMode($request->mode); clearWebConfigCacheKeys(); return back()->with('success','تم الحفظ.'); }
}
