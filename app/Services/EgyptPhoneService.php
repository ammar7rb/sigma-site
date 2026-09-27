<?php

namespace App\Services;

class EgyptPhoneService
{
    /**
     * Store Egyptian mobile numbers in one canonical form: +201XXXXXXXXX.
     *
     * The client may submit a local number (01...), 20..., or +20....
     * Normalizing before validation prevents duplicates of the same number.
     */
    public function normalize(mixed $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '20')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '+20' . $digits;
    }

    public function isValid(mixed $phone): bool
    {
        return preg_match('/^\+201[0125]\d{8}$/', $this->normalize($phone)) === 1;
    }
}
