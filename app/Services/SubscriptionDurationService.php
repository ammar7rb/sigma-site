<?php

namespace App\Services;

use Carbon\Carbon;

class SubscriptionDurationService
{
    public const DAYS = 'days';
    public const MONTHS = 'months';
    public const YEARS = 'years';
    public const LIFETIME = 'lifetime';

    public function normalize(?string $unit, ?int $value, ?int $legacyDays = null): array
    {
        $unit = strtolower(trim((string) $unit));
        if ($unit === 'day') {
            $unit = self::DAYS;
        } elseif ($unit === 'month') {
            $unit = self::MONTHS;
        } elseif ($unit === 'year') {
            $unit = self::YEARS;
        }

        if ($unit === self::LIFETIME) {
            return ['unit' => self::LIFETIME, 'value' => null];
        }

        if (!in_array($unit, [self::DAYS, self::MONTHS, self::YEARS], true)) {
            return $legacyDays && $legacyDays > 0
                ? ['unit' => self::DAYS, 'value' => $legacyDays]
                : ['unit' => self::LIFETIME, 'value' => null];
        }

        return ['unit' => $unit, 'value' => max(1, (int) $value)];
    }

    public function fromPackage(object $package): array
    {
        return $this->normalize(
            $package->duration_unit ?? null,
            $package->duration_value ?? null,
            $package->package_validity_days ?? null,
        );
    }

    public function expiresAt(Carbon $startedAt, string $unit, ?int $value): ?Carbon
    {
        return match ($unit) {
            self::DAYS => $startedAt->copy()->addDays((int) $value),
            self::MONTHS => $startedAt->copy()->addMonthsNoOverflow((int) $value),
            self::YEARS => $startedAt->copy()->addYearsNoOverflow((int) $value),
            default => null,
        };
    }

    public function label(string $unit, ?int $value): string
    {
        return $unit === self::LIFETIME
            ? 'lifetime'
            : sprintf('%d_%s', (int) $value, $unit);
    }

    public function remainingDays(?Carbon $expiresAt): ?int
    {
        return $expiresAt ? max(0, now()->diffInDays($expiresAt, false)) : null;
    }

    public function status(?Carbon $expiresAt, string $unit): string
    {
        if ($unit === self::LIFETIME) {
            return 'lifetime';
        }

        return $expiresAt && $expiresAt->lte(now()) ? 'expired' : 'active';
    }
}
