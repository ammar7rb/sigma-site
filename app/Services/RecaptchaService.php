<?php

namespace App\Services;


class RecaptchaService
{
    public static function verify(string $token, ?string $action = null): bool
    {
        // CAPTCHA is disabled on every authentication channel. Existing
        // throttling, temporary lockout and OTP remain the abuse controls.
        return true;
    }

    public static function verificationStatus(object|array $request, string $session, ?string $action = 'default', ?bool $firebase = false): array
    {
        session()->forget($session);
        return [
            'status' => true,
            'message' => translate('verification_successful'),
        ];
    }
}


?>
