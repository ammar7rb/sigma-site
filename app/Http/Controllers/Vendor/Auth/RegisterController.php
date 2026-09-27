<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Contracts\Repositories\AdminRepositoryInterface;
use Exception;
use App\Enums\SessionKey;
use Illuminate\Http\Request;
use App\Services\ShopService;
use App\Services\SellerRegistrationVerificationService;
use App\Services\SellerActivationService;
use App\Services\PolicyAcceptanceService;
use App\Models\SellerActivationTicket;
use App\Models\Seller;
use App\Services\VendorService;
use App\Services\EgyptPhoneService;
use Illuminate\Http\JsonResponse;
use App\Traits\EmailTemplateTrait;
use App\Traits\FileManagerTrait;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use App\Events\VendorRegistrationEvent;
use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Eloquent\Collection;
use App\Http\Requests\Vendor\VendorAddRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Repositories\VendorRegistrationReasonRepository;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\HelpTopicRepositoryInterface;
use App\Contracts\Repositories\VendorWalletRepositoryInterface;
use App\Contracts\Repositories\EmailTemplatesRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;

class RegisterController extends BaseController
{
    use EmailTemplateTrait, FileManagerTrait;

    public function __construct(
        private readonly VendorRepositoryInterface          $vendorRepo,
        private readonly AdminRepositoryInterface           $adminRepo,
        private readonly VendorWalletRepositoryInterface    $vendorWalletRepo,
        private readonly ShopRepositoryInterface            $shopRepo,
        private readonly VendorService                      $vendorService,
        private readonly ShopService                        $shopService,
        private readonly EmailTemplatesRepositoryInterface  $emailTemplatesRepo,
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly HelpTopicRepositoryInterface       $helpTopicRepo,
        private readonly VendorRegistrationReasonRepository $vendorRegistrationReasonRepo,
        private readonly SellerRegistrationVerificationService $sellerRegistrationVerificationService,
        private readonly SellerActivationService $sellerActivationService,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $businessMode = getWebConfig(name: 'business_mode');
        $vendorRegistration = getWebConfig(name: 'seller_registration');
        if ((isset($businessMode) && $businessMode == 'single') || (isset($vendorRegistration) && $vendorRegistration == 0)) {
            ToastMagic::warning(translate('access_denied') . '!!');
            return redirect('/');
        }
        $vendorRegistrationHeader = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_header'])['value']);
        $vendorRegistrationReasons = $this->vendorRegistrationReasonRepo->getListWhere(orderBy: ['priority' => 'desc'], filters: ['status' => 1], dataLimit: 'all');
        $sellWithUs = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_sell_with_us'])['value']);
        $downloadVendorApp = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'download_vendor_app'])['value']);
        $businessProcess = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'business_process_main_section'])['value']);
        $businessProcessStep = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'business_process_step'])['value']);
        $helpTopics = $this->helpTopicRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['type' => 'vendor_registration', 'status' => '1'],
            dataLimit: 'all');
        $requiredSellerPolicies = app(PolicyAcceptanceService::class)->requiredFor('seller');
        return view(VIEW_FILE_NAMES['seller_registration'], compact('vendorRegistrationHeader', 'vendorRegistrationReasons', 'sellWithUs', 'downloadVendorApp', 'helpTopics', 'businessProcess', 'businessProcessStep', 'requiredSellerPolicies'));
    }

    /**
     * Validate the account credentials before the registration wizard moves
     * to the seller/shop details step. This keeps duplicate-account feedback
     * beside the email/phone fields instead of showing it after submission.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $phone = trim((string) $request->input('phone'));
        $errors = [];

        if ($email === '') {
            $errors['email'] = translate('please_enter_your_email') . '.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = translate('please_enter_a_valid_email_address') . '.';
        } else {
            $emailExists = Seller::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();
            $admin = $this->adminRepo->getFirstWhere(['email' => $email]);
            if ($emailExists || $admin) {
                $errors['email'] = translate('The_email_has_already_been_taken');
            }
        }

        $phoneService = app(EgyptPhoneService::class);
        if (!$phoneService->isValid($phone)) {
            $errors['phone'] = translate('please_ensure_your_phone_number_is_valid_and_does_not_exceed_20_characters') . '.';
        } else {
            $normalizedPhone = $phoneService->normalize($phone);
            $digits = preg_replace('/\D/', '', $normalizedPhone) ?? '';
            $localWithZero = strlen($digits) > 2 ? '0' . substr($digits, 2) : '';
            $phoneCandidates = array_values(array_unique(array_filter([
                $normalizedPhone,
                $digits,
                '+' . $digits,
                $localWithZero,
            ])));

            if (Seller::query()->whereIn('phone', $phoneCandidates)->exists()) {
                $errors['phone'] = translate('The_phone_number_has_already_been_taken');
            }
        }

        return response()->json([
            'available' => $errors === [],
            'errors' => $errors,
        ]);
    }

    public function add(VendorAddRequest $request): JsonResponse
    {
        $policyService = app(PolicyAcceptanceService::class);
        $requiredPolicyIds = $policyService->requiredFor('seller')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $submittedPolicyIds = collect($request->input('policy_version_ids', []))->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (array_diff($requiredPolicyIds, $submittedPolicyIds)) {
            return response()->json([
                'errors' => [[
                    'message' => translate('required_policies_not_accepted'),
                ]],
            ]);
        }

        $adminEmail = $this->adminRepo->getFirstWhere(['admin_role_id' => 1]);
        if ($adminEmail && isset($adminEmail['email']) && $request['email'] === $adminEmail['email']) {
            return response()->json([
                'error' => translate('Email_already_exist_please_try_another_email'),
            ]);
        }
        $vendorData = $this->vendorService->getAddData($request);
        // Registration creates a usable seller account immediately. Activation
        // is a separate review workflow shown as a banner/ticket after login.
        $vendorData['status'] = 'approved';
        $vendorData['phone_verified_at'] = null;
        $vendor = $this->vendorRepo->add(data: $vendorData);
        $this->shopRepo->add($this->shopService->getAddShopDataForRegistration(request: $request, vendorId: $vendor['id']));
        $this->vendorWalletRepo->add($this->vendorService->getInitialWalletData(vendorId: $vendor['id']));
        $policyService->accept('seller', (int) $vendor['id'], $requiredPolicyIds);
        $this->sellerActivationService->openRegistrationTicket($vendor);

        session()->put(SessionKey::VENDOR_REGISTRATION_REFERENCE, $vendor['registration_reference']);
        $requiresPhoneVerification = $this->sellerRegistrationVerificationService->requiresPhoneVerification($vendor);
        $otpResult = ['status' => true, 'code' => 'seller_phone_verification_not_required'];
        if ($requiresPhoneVerification) {
            $otpResult = $this->sellerRegistrationVerificationService->sendOtp($vendor);
            if (!$otpResult['status']) {
                ToastMagic::warning($otpResult['message']);
            }
        } else {
            // Support-ticket activation does not block access to the newly
            // created account. The seller lands on the dashboard and can open
            // the activation conversation from the persistent red banner.
            auth('seller')->login($vendor->fresh());
        }

        $redirectRoute = $requiresPhoneVerification
            ? route('vendor.auth.registration.verify-phone')
            : route('vendor.dashboard.index');

        $data = [
            'vendorName' => $request['f_name'],
            'status' => 'pending',
            'subject' => translate('Vendor_Registration_Successfully_Completed'),
            'title' => translate('Vendor_Registration_Successfully_Completed'),
            'userType' => 'vendor',
            'templateName' => 'registration',
        ];
        try {
            event(new VendorRegistrationEvent(email: $request['email'], data: $data));
        } catch (Exception $e) {
            return response()->json(
                [
                    'status' => 1,
                    'redirectRoute' => $redirectRoute,
                    'otp' => $otpResult,
                ]
            );
        }
        return response()->json(
            [
                'status' => 1,
                'redirectRoute' => $redirectRoute,
                'otp' => $otpResult,
            ]
        );
    }

    public function getPhoneVerificationView(): View|RedirectResponse
    {
        $vendor = $this->sellerRegistrationVerificationService->findByReference(
            session(SessionKey::VENDOR_REGISTRATION_REFERENCE)
        );

        if (!$vendor || !$this->sellerRegistrationVerificationService->requiresPhoneVerification($vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        return view('vendor-views.auth.forgot-password.verify-otp-view', [
            'formAction' => route('vendor.auth.registration.verify-phone.submit'),
            'backRoute' => route('vendor.auth.login'),
            'resendRoute' => route('vendor.auth.registration.resend-otp'),
            'maskedPhone' => $this->sellerRegistrationVerificationService->getMaskedPhone($vendor),
        ]);
    }

    public function verifyPhone(Request $request): RedirectResponse
    {
        $token = implode('', (array) $request->input('token', []));
        if (!preg_match('/^\d{6}$/', $token)) {
            ToastMagic::error(translate('invalid_otp'));
            return back();
        }

        $vendor = $this->sellerRegistrationVerificationService->findByReference(
            session(SessionKey::VENDOR_REGISTRATION_REFERENCE)
        );
        if (!$vendor) {
            ToastMagic::error(translate('no_such_user_found'));
            return redirect()->route('vendor.auth.login');
        }

        $result = $this->sellerRegistrationVerificationService->verifyOtp($vendor, $token);
        if (!$result['status']) {
            ToastMagic::error($result['message']);
            return back();
        }

        session()->forget(SessionKey::VENDOR_REGISTRATION_REFERENCE);
        auth('seller')->login($vendor->fresh());
        ToastMagic::success(translate('verification_done_successfully'));
        return redirect()->route('vendor.dashboard.index');
    }

    public function resendOtp(): RedirectResponse
    {
        $vendor = $this->sellerRegistrationVerificationService->findByReference(
            session(SessionKey::VENDOR_REGISTRATION_REFERENCE)
        );
        if (!$vendor) {
            ToastMagic::error(translate('no_such_user_found'));
            return redirect()->route('vendor.auth.login');
        }

        $result = $this->sellerRegistrationVerificationService->sendOtp($vendor);
        $result['status'] ? ToastMagic::success($result['message']) : ToastMagic::error($result['message']);
        return back();
    }

    public function activation(): View|RedirectResponse
    {
        $vendor = auth('seller')->user();
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $ticket = SellerActivationTicket::query()
            ->where('seller_id', $vendor->id)
            ->whereNull('hidden_from_subject_at')
            ->latest('id')
            ->with('messages')
            ->first();

        if (!$ticket && $vendor->activation_status !== 'active') {
            $ticket = $this->sellerActivationService->openRegistrationTicket($vendor);
            $ticket?->load('messages');
        }

        return view('vendor-views.auth.activation-status', [
            'vendor' => $vendor->fresh(),
            'ticket' => $ticket,
            'supportTicketRequired' => true,
        ]);
    }

    /**
     * Lightweight polling endpoint for the seller activation conversation.
     * It intentionally returns only the conversation data that the seller is
     * allowed to see; the administration workspace remains private.
     */
    public function activationMessages(): JsonResponse
    {
        $vendor = auth('seller')->user();
        abort_unless($vendor, 401);

        $ticket = SellerActivationTicket::query()
            ->where('seller_id', $vendor->id)
            ->whereNull('hidden_from_subject_at')
            ->latest('id')
            ->with('messages')
            ->first();

        return response()->json([
            'ticket_id' => $ticket?->id,
            'status' => $ticket?->status,
            'activation_status' => $vendor->fresh()->activation_status,
            'dashboard_url' => route('vendor.dashboard.index'),
            'messages' => ($ticket?->messages ?? collect())->map(fn ($message) => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'body' => $message->body,
                'created_at' => optional($message->created_at)->format('Y-m-d H:i'),
                'attachments' => $message->attachment_full_url,
            ])->values() ?? [],
        ]);
    }

    public function activationMessage(Request $request): RedirectResponse|JsonResponse
    {
        $vendor = auth('seller')->user();
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $request->validate([
            'body' => 'required|string|max:2000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:6144',
        ]);

        $ticket = SellerActivationTicket::query()
            ->where('seller_id', $vendor->id)
            ->whereNull('hidden_from_subject_at')
            ->latest('id')
            ->first() ?? $this->sellerActivationService->openRegistrationTicket($vendor);

        if (!$ticket || !$ticket->isOpen()) {
            ToastMagic::error(translate('seller_activation_ticket_closed'));
            return back();
        }

        $storage = config('filesystems.disks.default') ?? 'public';
        $attachments = collect($request->file('attachments', []))->map(fn ($file) => [
            'file_name' => $this->fileUpload(dir: 'seller-activation-ticket/', format: $file->getClientOriginalExtension(), file: $file),
            'storage' => $storage,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ])->values()->all();

        $ticket->messages()->create([
            'sender_type' => 'seller',
            'body' => trim((string) $request->input('body')),
            'attachments' => $attachments,
            'is_automatic' => false,
        ]);

        if ($request->expectsJson()) {
            return $this->activationMessages();
        }

        ToastMagic::success(translate('message_sent_successfully'));
        return redirect()->route('vendor.auth.registration.activation');
    }
}
