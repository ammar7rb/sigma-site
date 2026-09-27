<?php

namespace App\Http\Controllers\RestAPI\v3\seller\auth;

use App\Events\VendorRegistrationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v3\SellerRegistrationRequest;
use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerActivationTicket;
use App\Models\Shop;
use App\Services\SellerRegistrationVerificationService;
use App\Services\SellerActivationService;
use App\Services\PolicyAcceptanceService;
use App\Traits\FileManagerTrait;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    use FileManagerTrait;

    public function store(
        SellerRegistrationRequest $request,
        SellerRegistrationVerificationService $verificationService,
        SellerActivationService $activationService,
        PolicyAcceptanceService $policyAcceptanceService
    ): JsonResponse
    {
        $adminEmail = Admin::where('admin_role_id', 1)->select('email')->first();
        if ($adminEmail && $request['email'] === $adminEmail) {
            return response()->json([
                'message' => translate('Email_already_exist_please_try_another_email'),
                'error' => translate('Email_already_exist_please_try_another_email'),
            ], 403);
        }
        $acceptedPolicyIds = array_map('intval', $request->input('policy_version_ids', []));
        $requiredPolicyIds = $policyAcceptanceService->requiredFor('seller')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($requiredPolicyIds, $acceptedPolicyIds)) {
            return response()->json([
                'code' => 'required_policies_not_accepted',
                'message' => translate('required_policies_must_be_accepted'),
            ], 422);
        }
        $storage = config('filesystems.disks.default') ?? 'public';
        DB::beginTransaction();
        try {
            $seller = new Seller();
            $fallbackName = trim((string) Str::before($request->email, '@')) ?: 'Sigma seller';
            $seller->f_name = $request->f_name ?: $fallbackName;
            $seller->l_name = $request->l_name ?: '';
            $seller->phone = $request->phone;
            $seller->email = $request->email;
            if (Schema::hasColumn('sellers', 'image') && $request->hasFile('image')) {
                $seller->image = ImageManager::upload('seller/', 'webp', $request->file('image'));
            }
            $seller->password = bcrypt($request->password);
            // The account is usable immediately; activation is an independent
            // admin review case represented by the red banner in the client.
            $seller->status = 'approved';
            $seller->activation_status = 'pending';
            $seller->activation_requested_at = now();
            $seller->phone_verified_at = null;
            $seller->registration_reference = (string) Str::uuid();
            if (Schema::hasColumn('sellers', 'auth_token')) {
                $seller->auth_token = Str::random(50);
            }
            $seller->save();
            $policyAcceptanceService->accept('seller', (int) $seller->id, $acceptedPolicyIds);

            if (Schema::hasTable('shops')) {
                $shop = new Shop();
                $shop->seller_id = $seller->id;
                $shop->name = $request->shop_name ?: $fallbackName;
                $shop->address = $request->shop_address ?: '';
                $shop->contact = $request->phone;
                $shop->image = $request->file('logo')
                    ? ImageManager::upload('shop/', 'webp', $request->file('logo'))
                    : 'def.png';
                $shop->image_storage_type = $request->hasFile('logo') ? $storage : 'public';
                $shop->banner = $request->file('banner')
                    ? ImageManager::upload('shop/banner/', 'webp', $request->file('banner'))
                    : 'def.png';
                $shop->banner_storage_type = $request->hasFile('banner') ? $storage : 'public';
                $shop->bottom_banner = $request->file('bottom_banner')
                    ? ImageManager::upload('shop/banner/', 'webp', $request->file('bottom_banner'))
                    : null;
                $shop->bottom_banner_storage_type = $request->has('bottom_banner') ? $storage : null;
                $shop->tax_identification_number = $request['tax_identification_number'] ?? '';
                $shop->tin_expire_date = $request['tin_expire_date'] ? Carbon::parse($request['tin_expire_date']) : null;
                $shop->tin_certificate = $request->file('tin_certificate') ? ImageManager::file_upload(
                    dir: 'shop/documents/',
                    format: $request->file('tin_certificate')->getClientOriginalExtension(),
                    file: $request->file('tin_certificate')) : null;
                $shop->tin_certificate_storage_type = $request->has('tin_certificate') ? $storage : null;
                $shop->save();
            }

            if (Schema::hasTable('seller_wallets')) DB::table('seller_wallets')->insert([
                'seller_id' => $seller['id'],
                'withdrawn' => 0,
                'commission_given' => 0,
                'total_earning' => 0,
                'pending_withdraw' => 0,
                'delivery_charge_earned' => 0,
                'collected_cash' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (Schema::hasTable('seller_activation_tickets')) {
                $activationService->openRegistrationTicket($seller);
            }
            DB::commit();
            $data = [
                'vendorName' => $request['f_name'],
                'status' => 'approved',
                'subject' => translate('Vendor_Registration_Successfully_Completed'),
                'title' => translate('Vendor_Registration_Successfully_Completed'),
                'userType' => 'vendor',
                'templateName' => 'registration',
            ];
            try {
                event(new VendorRegistrationEvent(email: $request['email'], data: $data));
            } catch (\Throwable) {
                // Registration and phone verification must not fail when email delivery is unavailable.
            }

            if (! $verificationService->requiresPhoneVerification($seller)) {
                $otpResult = [
                    'status' => true,
                    'code' => 'seller_phone_verification_not_required',
                    'message' => translate('verification_not_required'),
                    'resend_after' => 0,
                ];
            } elseif ($verificationService->isFirebaseEnabled() && !$request->filled('firebase_session_info')) {
                $otpResult = [
                    'status' => false,
                    'code' => 'firebase_session_info_required',
                    'message' => 'Start Firebase phone verification on the device, then submit its session info.',
                    'delivery_method' => 'firebase_client',
                    'resend_after' => 0,
                ];
            } else {
                $otpResult = $verificationService->sendOtp($seller, $request->input('firebase_session_info'));
            }

            return response()->json([
                'message' => 'Shop apply successfully!',
                'token' => $seller->auth_token ?? null,
                'code' => 'seller_phone_verification_required',
                'registration_reference' => $seller->registration_reference,
                'masked_phone' => $verificationService->getMaskedPhone($seller),
                'otp' => $otpResult + [
                    'required' => $verificationService->requiresPhoneVerification($seller),
                ],
                'eligibility' => $verificationService->getEligibility($seller),
            ], 200);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Seller registration transaction failed.', [
                'exception' => $e::class,
                'code' => (string) $e->getCode(),
            ]);
            return response()->json([
                'code' => 'seller_registration_failed',
                'message' => translate('registration_failed_try_again'),
            ], 500);
        }

    }

    public function sendOtp(Request $request, SellerRegistrationVerificationService $verificationService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'registration_reference' => 'required|uuid',
            'firebase_session_info' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);
        }

        $seller = $verificationService->findByReference($request->input('registration_reference'));
        if (!$seller) {
            return $this->registrationNotFoundResponse();
        }

        if ($verificationService->requiresPhoneVerification($seller)
            && $verificationService->isFirebaseEnabled()
            && !$request->filled('firebase_session_info')) {
            return response()->json([
                'code' => 'firebase_session_info_required',
                'message' => 'Start Firebase phone verification on the device, then submit its session info.',
                'delivery_method' => 'firebase_client',
            ], 422);
        }

        $result = $verificationService->sendOtp($seller, $request->input('firebase_session_info'));
        return response()->json($result, $result['status'] ? 200 : ($result['code'] === 'otp_resend_wait' ? 429 : 422));
    }

    public function verifyOtp(Request $request, SellerRegistrationVerificationService $verificationService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'registration_reference' => 'required|uuid',
            'otp' => 'required|digits:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);
        }

        $seller = $verificationService->findByReference($request->input('registration_reference'));
        if (!$seller) {
            return $this->registrationNotFoundResponse();
        }

        $result = $verificationService->verifyOtp($seller, (string) $request->input('otp'));
        return response()->json($result, $result['status'] ? 200 : 422);
    }

    public function status(Request $request, SellerRegistrationVerificationService $verificationService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'registration_reference' => 'required|uuid',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);
        }

        $seller = $verificationService->findByReference($request->input('registration_reference'));
        if (!$seller) {
            return $this->registrationNotFoundResponse();
        }

        return response()->json([
            'registration_reference' => $seller->registration_reference,
            'masked_phone' => $verificationService->getMaskedPhone($seller),
            'activation' => $this->activationPayload($seller, $verificationService),
            'eligibility' => $verificationService->getEligibility($seller),
        ]);
    }

    public function activationStatus(Request $request, SellerRegistrationVerificationService $verificationService): JsonResponse
    {
        $seller = $this->sellerFromRegistrationReference($request, $verificationService);
        if ($seller instanceof JsonResponse) return $seller;

        return response()->json([
            'registration_reference' => $seller->registration_reference,
            'activation' => $this->activationPayload($seller, $verificationService),
            'eligibility' => $verificationService->getEligibility($seller),
        ]);
    }

    public function activationTicket(Request $request, SellerRegistrationVerificationService $verificationService, SellerActivationService $activationService): JsonResponse
    {
        $seller = $this->sellerFromRegistrationReference($request, $verificationService);
        if ($seller instanceof JsonResponse) return $seller;
        if ($seller->activation_status === 'active') {
            return response()->json(['code' => 'seller_activation_already_completed', 'message' => translate('seller_activation_already_completed')], 409);
        }

        return response()->json(['activation_ticket' => $this->ticketSummary($activationService->openRegistrationTicket($seller))]);
    }

    public function activationTicketMessages(Request $request, SellerRegistrationVerificationService $verificationService): JsonResponse
    {
        $seller = $this->sellerFromRegistrationReference($request, $verificationService);
        if ($seller instanceof JsonResponse) return $seller;
        if ($seller->activation_status === 'active') {
            return response()->json(['activation_completed' => true, 'activation_ticket' => null, 'messages' => []]);
        }
        $ticket = $this->findTicketForSeller($seller, $request->integer('ticket_id'));
        if (! $ticket) return response()->json(['activation_ticket' => null, 'messages' => []]);

        return response()->json([
            'activation_ticket' => $this->ticketSummary($ticket),
            'messages' => $ticket->messages()->get()->map(fn ($message) => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'body' => $message->body,
                'attachments' => $message->attachment_full_url,
                'is_automatic' => $message->is_automatic,
                'created_at' => $message->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    public function sendActivationTicketMessage(Request $request, SellerRegistrationVerificationService $verificationService, SellerActivationService $activationService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'registration_reference' => 'required|uuid',
            'body' => 'nullable|string|max:2000|required_without:attachments',
            'attachments' => 'nullable|array|max:5|required_without:body',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:6144',
            'ticket_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);

        $seller = $verificationService->findByReference($request->input('registration_reference'));
        if (! $seller) return $this->registrationNotFoundResponse();
        if ($seller->activation_status === 'active') {
            return response()->json(['code' => 'seller_activation_already_completed', 'message' => translate('seller_activation_already_completed')], 409);
        }
        $ticket = $this->findTicketForSeller($seller, $request->integer('ticket_id')) ?? $activationService->openRegistrationTicket($seller);
        if (! $ticket->isOpen()) {
            return response()->json(['code' => 'seller_activation_ticket_closed', 'message' => translate('seller_activation_ticket_closed')], 409);
        }

        $message = $ticket->messages()->create([
            'sender_type' => 'seller',
            'body' => $request->string('body')->trim()->toString(),
            'attachments' => $this->storeActivationAttachments($request),
            'is_automatic' => false,
        ]);

        return response()->json([
            'activation_ticket' => $this->ticketSummary($ticket->fresh()),
            'message' => [
                'id' => $message->id, 'sender_type' => $message->sender_type,
                'body' => $message->body, 'attachments' => $message->attachment_full_url,
                'is_automatic' => $message->is_automatic, 'created_at' => $message->created_at?->toISOString(),
            ],
        ], 201);
    }

    private function sellerFromRegistrationReference(Request $request, SellerRegistrationVerificationService $verificationService): Seller|JsonResponse
    {
        $validator = Validator::make($request->all(), ['registration_reference' => 'required|uuid']);
        if ($validator->fails()) return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 422);

        return $verificationService->findByReference($request->input('registration_reference')) ?? $this->registrationNotFoundResponse();
    }

    private function storeActivationAttachments(Request $request): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        return collect($request->file('attachments', []))->map(fn ($file) => [
            'file_name' => $this->fileUpload(dir: 'seller-activation-ticket/', format: $file->getClientOriginalExtension(), file: $file),
            'storage' => $storage,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
        ])->values()->all();
    }

    private function activationPayload(Seller $seller, SellerRegistrationVerificationService $verificationService): array
    {
        $ticket = $this->findTicketForSeller($seller);
        $active = $seller->activation_status === 'active';
        // Account activation is always handled through the unified ticket. The
        // verification mode controls OTP only, never whether the ticket exists.
        $supportTicketRequired = true;

        return [
            'status' => $seller->activation_status,
            'support_ticket_required' => $supportTicketRequired,
            'ticket' => $ticket ? $this->ticketSummary($ticket) : null,
            'banner' => $active
                ? ['visible' => false, 'state' => 'activated', 'color' => 'green', 'message' => translate('seller_account_activated')]
                : ['visible' => true, 'state' => 'activation_required', 'color' => 'red', 'message' => translate('seller_activation_required'), 'action' => 'open_activation_ticket'],
        ];
    }

    private function findTicketForSeller(Seller $seller, ?int $ticketId = null): ?SellerActivationTicket
    {
        $query = SellerActivationTicket::query()->where('seller_id', $seller->id)->whereNull('hidden_from_subject_at');
        return $ticketId ? $query->whereKey($ticketId)->first() : $query->latest('id')->first();
    }

    private function ticketSummary(SellerActivationTicket $ticket): array
    {
        return [
            'id' => $ticket->id, 'status' => $ticket->status, 'subject' => $ticket->subject,
            'opened_at' => $ticket->opened_at?->toISOString(),
            'automatic_message' => $ticket->messages()->where('is_automatic', true)->value('body'),
        ];
    }

    private function supportTicketDisabledResponse(): JsonResponse
    {
        return response()->json(['code' => 'seller_activation_support_ticket_not_required', 'message' => translate('seller_activation_support_ticket_not_required')], 409);
    }

    private function registrationNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'errors' => [
                ['code' => 'seller_registration_not_found', 'message' => translate('no_such_user_found')],
            ],
        ], 404);
    }
}
