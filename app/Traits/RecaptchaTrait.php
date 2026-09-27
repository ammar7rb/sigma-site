<?php

namespace App\Traits;

use Gregwar\Captcha\PhraseBuilder;
use Gregwar\Captcha\CaptchaBuilder;
use Illuminate\Support\Facades\Session;

trait RecaptchaTrait
{
    protected function isGoogleRecaptchaValid(string $reCaptchaValue): bool
    {
        // CAPTCHA has been retired from the platform. Authentication abuse is
        // controlled by throttling, temporary lockout, OTP and support review.
        return true;
    }

    public function generateDefaultReCaptcha(int $captureLength): CaptchaBuilder
    {
        $phrase = new PhraseBuilder;
        $code = $phrase->build($captureLength);
        $builder = new CaptchaBuilder($code, $phrase);
        $builder->setBackgroundColor(220, 210, 230);
        $builder->setMaxAngle(25);
        $builder->setMaxBehindLines(0);
        $builder->setMaxFrontLines(0);
        $builder->build($width = 100, $height = 40, $font = null);
        return $builder;
    }


    public function saveRecaptchaValueInSession(string $sessionKey, string $sessionValue):void{
        if (Session::has($sessionKey)) {
            Session::forget($sessionKey);
        }
        Session::put($sessionKey, $sessionValue);
    }
}
