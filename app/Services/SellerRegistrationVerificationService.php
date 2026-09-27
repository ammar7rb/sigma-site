<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\PhoneOrEmailVerification;
use App\Models\Seller;
use App\Utils\SMSModule;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SellerRegistrationVerificationService
{
    public const SETTING_VERIFICATION_MODE = 'seller_activation_verification_mode';
    public const SETTING_OTP_TTL_MINUTES = 'seller_activation_otp_ttl_minutes';
    public const SETTING_OTP_MAX_ATTEMPTS = 'seller_activation_otp_max_attempts';
    public const SETTING_OTP_RESEND_SECONDS = 'seller_activation_otp_resend_seconds';
    public const MODE_NONE = 'none';
    public const MODE_OTP_ONLY = 'otp_only';
    public const MODE_SUPPORT_TICKET_ONLY = 'support_ticket_only';
    public const MODE_OTP_AND_SUPPORT_TICKET = 'otp_and_support_ticket';

    private const DEFAULT_OTP_TTL_MINUTES = 10;
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_RESEND_SECONDS = 60;
    private const DEFAULT_BLOCK_SECONDS = 600;

    public function __construct(
        private readonly FirebaseService $firebaseService,
    ) {
    }

    public function findByReference(?string $reference): ?Seller
    {
        if (!$reference) {
            return null;
        }

        return Seller::where('registration_reference', $reference)->first();
    }

    public function isPhoneVerified(Seller $seller): bool
    {
        return $seller->phone_verified_at !== null || $seller->registration_reference === null;
    }

    public function requiresPhoneVerification(Seller $seller): bool
    {
        return $this->requiresOtp() && !$this->isPhoneVerified($seller);
    }

    public function verificationMode(): string
    {
        $value = BusinessSetting::query()
            ->where('type', self::SETTING_VERIFICATION_MODE)
            ->value('value');

        if ($value === null) {
            return self::MODE_SUPPORT_TICKET_ONLY;
        }

        $decoded = json_decode($value, true);
        $mode = is_array($decoded) ? ($decoded['mode'] ?? null) : ($decoded ?? $value);

        return in_array($mode, $this->allowedVerificationModes(), true)
            ? $mode
            : self::MODE_SUPPORT_TICKET_ONLY;
    }

    public function setVerificationMode(string $mode): void
    {
        if (!in_array($mode, $this->allowedVerificationModes(), true)) {
            throw new InvalidArgumentException('Unsupported seller activation verification mode.');
        }

        BusinessSetting::query()->updateOrCreate(
            ['type' => self::SETTING_VERIFICATION_MODE],
            ['value' => $mode]
        );
    }

    public function otpSettings(): array
    {
        return [
            'ttl_minutes' => $this->settingInteger(self::SETTING_OTP_TTL_MINUTES, self::DEFAULT_OTP_TTL_MINUTES),
            'max_attempts' => $this->settingInteger(self::SETTING_OTP_MAX_ATTEMPTS, self::DEFAULT_MAX_ATTEMPTS),
            'resend_seconds' => $this->settingInteger(self::SETTING_OTP_RESEND_SECONDS, self::DEFAULT_RESEND_SECONDS),
        ];
    }

    public function setOtpSettings(int $ttlMinutes, int $maxAttempts, int $resendSeconds): void
    {
        foreach ([self::SETTING_OTP_TTL_MINUTES => $ttlMinutes, self::SETTING_OTP_MAX_ATTEMPTS => $maxAttempts, self::SETTING_OTP_RESEND_SECONDS => $resendSeconds] as $type => $value) {
            BusinessSetting::query()->updateOrCreate(['type' => $type], ['value' => $value]);
        }
    }

    public function requiresOtp(): bool
    {
        return in_array($this->verificationMode(), [
            self::MODE_OTP_ONLY,
            self::MODE_OTP_AND_SUPPORT_TICKET,
        ], true);
    }

    public function requiresSupportTicket(): bool
    {
        return in_array($this->verificationMode(), [
            self::MODE_SUPPORT_TICKET_ONLY,
            self::MODE_OTP_AND_SUPPORT_TICKET,
        ], true);
    }

    public function registrationRequirements(Seller $seller): array
    {
        return [
            'verification_mode' => $this->verificationMode(),
            'otp_required' => $this->requiresOtp(),
            // Activation is always completed through the admin case. The
            // verification mode only controls phone OTP, not account access.
            'support_ticket_required' => true,
            'phone_verification_required' => $this->requiresPhoneVerification($seller),
        ];
    }

    public function getEligibility(Seller $seller): array
    {
        $phoneVerificationRequired = $this->requiresPhoneVerification($seller);
        $phoneVerified = !$phoneVerificationRequired;
        $accountStatus = $seller->status;

        $nextStep = match (true) {
            $phoneVerificationRequired => 'verify_phone',
            $seller->activation_status !== 'active' => 'await_activation_ticket',
            $accountStatus === 'pending' => 'await_admin_approval',
            $accountStatus === 'approved' => 'ready_for_insurance',
            default => 'account_' . $accountStatus,
        };

        return [
            'phone_verified' => $phoneVerified,
            'phone_verified_at' => $seller->phone_verified_at?->toISOString(),
            'account_status' => $accountStatus,
            'activation_status' => $seller->activation_status,
            'can_login' => $phoneVerified && $accountStatus === 'approved',
            'next_step' => $nextStep,
            'verification' => $this->registrationRequirements($seller),
        ];
    }

    public function sendOtp(Seller $seller, ?string $firebaseSessionInfo = null): array
    {
        if ($this->isPhoneVerified($seller)) {
            return [
                'status' => true,
                'code' => 'seller_phone_already_verified',
                'message' => translate('verification_done_successfully'),
                'resend_after' => 0,
            ];
        }

        $identity = $this->verificationIdentity($seller);
        $verification = PhoneOrEmailVerification::where('phone_or_email', $identity)->first();
        $resendAfter = $this->getResendAfter($verification);

        if ($resendAfter > 0) {
            return [
                'status' => false,
                'code' => 'otp_resend_wait',
                'message' => translate('please_try_again_after_') . $resendAfter . ' ' . translate('seconds'),
                'resend_after' => $resendAfter,
            ];
        }

        $deliveryMethod = 'sms';
        $token = env('APP_MODE') === 'live' ? (string) random_int(100000, 999999) : '123456';

        if ($this->isFirebaseEnabled()) {
            $deliveryMethod = $firebaseSessionInfo ? 'firebase_client' : 'firebase';

            if ($firebaseSessionInfo) {
                $token = $firebaseSessionInfo;
            } else {
                try {
                    $firebaseResponse = $this->firebaseService->sendOtp($seller->phone);
                } catch (Throwable) {
                    $firebaseResponse = ['status' => 'error', 'errors' => 'OTP_send_failed'];
                }

                if (($firebaseResponse['status'] ?? 'error') !== 'success') {
                    return [
                        'status' => false,
                        'code' => 'otp_send_failed',
                        'message' => translate(strtolower($firebaseResponse['errors'] ?? 'OTP_send_failed')),
                        'resend_after' => 0,
                    ];
                }

                $token = $firebaseResponse['sessionInfo'];
            }
        } else {
            $smsResponse = SMSModule::sendCentralizedSMS($seller->phone, $token);
            if (env('APP_MODE') !== 'live') {
                $smsResponse = 'success';
            }

            if ($smsResponse !== 'success') {
                return [
                    'status' => false,
                    'code' => 'otp_send_failed',
                    'message' => translate('something_went_wrong.') . ' ' . translate('please_try_again_after_sometime'),
                    'resend_after' => 0,
                ];
            }
        }

        PhoneOrEmailVerification::updateOrCreate(
            ['phone_or_email' => $identity],
            [
                'token' => $token,
                'otp_hit_count' => 0,
                'is_temp_blocked' => 0,
                'temp_block_time' => null,
                'expires_at' => now()->addMinutes($this->otpTtlMinutes()),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return [
            'status' => true,
            'code' => 'seller_registration_otp_sent',
            'message' => translate('OTP_sent_successfully'),
            'delivery_method' => $deliveryMethod,
            'resend_after' => $this->resendSeconds(),
            'expires_in' => $this->otpTtlMinutes() * 60,
        ];
    }

    public function verifyOtp(Seller $seller, string $otp): array
    {
        if ($this->isPhoneVerified($seller)) {
            return [
                'status' => true,
                'code' => 'seller_phone_already_verified',
                'message' => translate('verification_done_successfully'),
                'eligibility' => $this->getEligibility($seller),
            ];
        }

        $identity = $this->verificationIdentity($seller);
        $verification = PhoneOrEmailVerification::where('phone_or_email', $identity)->first();

        if (!$verification) {
            return $this->failedVerification('otp_not_requested', translate('OTP_is_not_matched'));
        }

        if ($verification->expires_at && $verification->expires_at->isPast()) {
            $verification->delete();
            return $this->failedVerification('otp_expired', translate('OTP_is_not_matched'));
        }

        $blockSeconds = $this->blockSeconds();
        if ($verification->is_temp_blocked && $verification->temp_block_time) {
            $blockedUntil = $verification->temp_block_time->copy()->addSeconds($blockSeconds);
            if ($blockedUntil->isFuture()) {
                return $this->failedVerification(
                    'otp_temp_blocked',
                    translate('please_try_again_after_') . now()->diffInSeconds($blockedUntil) . ' ' . translate('seconds')
                );
            }

            $verification->update([
                'otp_hit_count' => 0,
                'is_temp_blocked' => 0,
                'temp_block_time' => null,
            ]);
        }

        $verified = false;
        if (strlen((string) $verification->token) > 6) {
            try {
                $firebaseResponse = $this->firebaseService->verifyOtp($verification->token, $seller->phone, $otp);
                $verified = ($firebaseResponse['status'] ?? 'error') === 'success';
            } catch (Throwable) {
                $verified = false;
            }
        } else {
            $verified = hash_equals((string) $verification->token, $otp);
        }

        if (!$verified) {
            $attempts = $verification->otp_hit_count + 1;
            $blocked = $attempts >= $this->maxAttempts();
            $verification->update([
                'otp_hit_count' => $attempts,
                'is_temp_blocked' => $blocked,
                'temp_block_time' => $blocked ? now() : null,
            ]);

            return $this->failedVerification(
                $blocked ? 'otp_temp_blocked' : 'invalid_otp',
                $blocked ? translate('Too_many_attempts.') : translate('OTP_is_not_matched')
            );
        }

        DB::transaction(function () use ($seller, $verification) {
            $seller->update(['phone_verified_at' => now()]);
            $verification->delete();
        });

        $seller->refresh();

        return [
            'status' => true,
            'code' => 'seller_phone_verified',
            'message' => translate('verification_done_successfully'),
            'eligibility' => $this->getEligibility($seller),
        ];
    }

    public function isFirebaseEnabled(): bool
    {
        $setting = getWebConfig(name: 'firebase_otp_verification') ?? [];
        return is_array($setting) && (int) ($setting['status'] ?? 0) === 1;
    }

    public function getMaskedPhone(Seller $seller): string
    {
        $phone = (string) $seller->phone;
        if (strlen($phone) <= 4) {
            return $phone;
        }

        return str_repeat('*', strlen($phone) - 4) . substr($phone, -4);
    }

    private function verificationIdentity(Seller $seller): string
    {
        return 'seller_registration:' . $seller->id . ':' . $seller->phone;
    }

    private function getResendAfter(?PhoneOrEmailVerification $verification): int
    {
        if (!$verification?->created_at) {
            return 0;
        }

        $availableAt = $verification->created_at->copy()->addSeconds($this->resendSeconds());
        return $availableAt->isFuture() ? (int) now()->diffInSeconds($availableAt) : 0;
    }

    private function maxAttempts(): int
    {
        return $this->settingInteger(self::SETTING_OTP_MAX_ATTEMPTS, self::DEFAULT_MAX_ATTEMPTS);
    }

    private function resendSeconds(): int
    {
        return $this->settingInteger(self::SETTING_OTP_RESEND_SECONDS, self::DEFAULT_RESEND_SECONDS);
    }

    private function otpTtlMinutes(): int
    {
        return $this->settingInteger(self::SETTING_OTP_TTL_MINUTES, self::DEFAULT_OTP_TTL_MINUTES);
    }

    private function settingInteger(string $type, int $fallback): int
    {
        $value = (int) BusinessSetting::query()->where('type', $type)->value('value');
        return $value > 0 ? $value : $fallback;
    }

    private function blockSeconds(): int
    {
        $configured = (int) (getWebConfig(name: 'temporary_block_time') ?? 0);
        return $configured > 0 ? $configured : self::DEFAULT_BLOCK_SECONDS;
    }

    private function failedVerification(string $code, string $message): array
    {
        return [
            'status' => false,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function allowedVerificationModes(): array
    {
        return [
            self::MODE_NONE,
            self::MODE_OTP_ONLY,
            self::MODE_SUPPORT_TICKET_ONLY,
            self::MODE_OTP_AND_SUPPORT_TICKET,
        ];
    }
}
