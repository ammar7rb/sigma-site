<?php

namespace App\Services;

class AdministrativeReferenceService
{
    public static function parse(?string $value): ?array
    {
        $value = strtoupper(trim((string) $value));
        if (!preg_match('/^([CV])\s*[-#]?\s*(\d+)$/', $value, $matches)) {
            return null;
        }

        return [
            'type' => $matches[1] === 'C' ? 'users' : 'sellers',
            'id' => (int) $matches[2],
        ];
    }

    public static function format(string $subjectType, int $id): string
    {
        return match ($subjectType) {
            'customer', 'users' => 'C' . $id,
            'seller', 'sellers', 'vendor' => 'V' . $id,
            default => '#' . $id,
        };
    }
}
