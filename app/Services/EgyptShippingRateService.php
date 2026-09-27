<?php

namespace App\Services;

use App\Models\EgyptShippingZoneRate;
use Illuminate\Support\Collection;

class EgyptShippingRateService
{
    private const SIGMA_MULTIPLIER = 3.0;
    public function findRate(array|object|null $address): ?EgyptShippingZoneRate
    {
        $parts = $this->addressParts($address);
        if (!$this->isEgypt($parts['country'])) {
            return null;
        }

        $rates = EgyptShippingZoneRate::query()
            ->where('country_code', 'EG')
            ->where('status', true)
            ->get();

        return $rates
            ->filter(function (EgyptShippingZoneRate $rate) use ($parts): bool {
                foreach (['governorate', 'district', 'area', 'street'] as $field) {
                    $configured = $this->normalize($rate->{$field});
                    $requested = $parts[$field];
                    if ($configured !== null && ($requested === null || $configured !== $requested)) {
                        return false;
                    }
                }
                return true;
            })
            ->sortByDesc(function (EgyptShippingZoneRate $rate): int {
                return collect(['governorate', 'district', 'area', 'street'])
                    ->map(fn (string $field): int => $this->normalize($rate->{$field}) !== null ? 1 : 0)
                    ->sum();
            })
            ->first();
    }

    public function costFor(array|object|null $address, string $optionKey): ?float
    {
        return $this->quote($address, $optionKey)['total'] ?? null;
    }

    /**
     * Returns the immutable price components stored with the order. The optional
     * distance comes from the Egypt-restricted map; peak pricing is only applied
     * when the caller explicitly marks the quote as peak time.
     */
    public function quote(array|object|null $address, string $optionKey, ?float $distanceKm = null, bool $isPeak = false): ?array
    {
        $optionKey = $optionKey === 'sigma' ? 'sigma' : 'normal';
        $rate = $this->findRate($address);
        if (!$rate || ($optionKey === 'sigma' && !$rate->sigma_available) || ($optionKey === 'normal' && !$rate->normal_available)) {
            return null;
        }

        $normalBase = $rate->normal_cost;
        if ($normalBase === null) {
            return null;
        }

        $distanceKm = max(0, (float) ($distanceKm ?? 0));
        $billableDistance = max(0, $distanceKm - (float) $rate->included_distance_km);
        $distanceCost = $billableDistance * (float) $rate->price_per_km;
        $normalSubtotal = (float) $normalBase + $distanceCost;
        // Sigma is always the same route with a three-times express tariff.
        // Keeping this rule here prevents an admin or a stale client from
        // accidentally quoting a different multiplier.
        $sigmaSurcharge = $optionKey === 'sigma' ? $normalSubtotal * (self::SIGMA_MULTIPLIER - 1) : 0.0;
        $peakMultiplier = $isPeak ? max(1, (float) $rate->peak_multiplier) : 1.0;
        $subtotal = $normalSubtotal + $sigmaSurcharge;

        return [
            'rate_id' => $rate->id,
            'country_code' => 'EG',
            'option' => $optionKey,
            'base_cost' => round((float) $normalBase * ($optionKey === 'sigma' ? self::SIGMA_MULTIPLIER : 1), 3),
            'distance_km' => round($distanceKm, 3),
            'included_distance_km' => round((float) $rate->included_distance_km, 3),
            'billable_distance_km' => round($billableDistance, 3),
            'price_per_km' => round((float) $rate->price_per_km, 3),
            'distance_cost' => round($distanceCost, 3),
            'sigma_surcharge' => round($sigmaSurcharge, 3),
            'sigma_multiplier' => $optionKey === 'sigma' ? self::SIGMA_MULTIPLIER : 1.0,
            'is_peak' => $isPeak,
            'peak_multiplier' => round($peakMultiplier, 3),
            'total' => round($subtotal * $peakMultiplier, 3),
            'location' => array_filter([
                'governorate' => $rate->governorate,
                'district' => $rate->district,
                'area' => $rate->area,
                'street' => $rate->street,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    public function isEgyptAddress(array|object|null $address): bool
    {
        $parts = $this->addressParts($address);

        return $this->isEgypt($parts['country'])
            && $this->hasEgyptianCoordinates($parts['latitude'], $parts['longitude']);
    }

    public function hasConfiguredRates(): bool
    {
        return EgyptShippingZoneRate::query()->where('status', true)->exists();
    }

    /** Returns the canonical governorate name selected by the customer. */
    public function governorateFor(array|object|null $address): ?string
    {
        $parts = $this->addressParts($address);
        $candidate = $parts['governorate'];
        if ($candidate === null) {
            return null;
        }

        foreach (config('egypt.governorates', []) as $governorate) {
            if ($this->normalize($governorate) === $candidate) {
                return $governorate;
            }
        }

        return null;
    }

    /**
     * Gets a real driving distance from the origin saved on the matched Egypt
     * shipping-zone rate. Each price rule therefore owns both its tariff and
     * its dispatch point; there is no separate point configuration to drift.
     */
    public function distanceFromDispatch(array|object|null $address): ?float
    {
        $data = is_object($address)
            ? (method_exists($address, 'toArray') ? $address->toArray() : get_object_vars($address))
            : (array) $address;

        $rate = $this->findRate($data);
        if (!$rate || !is_numeric($rate->dispatch_latitude) || !is_numeric($rate->dispatch_longitude)) {
            return null;
        }

        // A persisted result avoids another paid route request, but it is only
        // valid for the same active shipping-zone rule. Updating the zone's
        // origin changes its route-cache key and creates a fresh quote.
        if ((int) ($data['shipping_zone_rate_id'] ?? 0) === (int) $rate->id
            && isset($data['distance_km'])
            && is_numeric($data['distance_km'])
            && (float) $data['distance_km'] >= 0) {
            return round((float) $data['distance_km'], 3);
        }

        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (($latitude === 0.0 && $longitude === 0.0)
            || !$this->hasEgyptianCoordinates($latitude, $longitude)) {
            return null;
        }

        return app(GoogleMapsShippingLocationService::class)
            ->routeDistanceKm($rate, $latitude, $longitude);
    }

    /**
     * Fixed governorate tariffs do not need a route distance to produce the
     * configured price. Variable per-kilometre tariffs remain fail-closed.
     */
    public function pricingDistanceFromDispatch(array|object|null $address): ?float
    {
        $rate = $this->findRate($address);
        // Mobile and web checkout use the fixed tariff assigned to the selected
        // governorate. Coordinates and paid route lookups are intentionally not
        // part of the customer journey.
        return $rate ? 0.0 : null;
    }

    private function addressParts(array|object|null $address): array
    {
        $data = is_object($address)
            ? (method_exists($address, 'toArray') ? $address->toArray() : get_object_vars($address))
            : (array) $address;
        $explicitGovernorate = $data['governorate'] ?? $data['state'] ?? null;
        $city = $data['city'] ?? null;

        return [
            'country' => $this->normalize($data['country'] ?? $data['country_code'] ?? null),
            // New forms submit a governorate. For old/manual addresses, infer
            // it from the city and the text chosen from Google Maps.
            'governorate' => $this->resolveGovernorate($explicitGovernorate, $city, $data['address'] ?? null),
            'district' => $this->normalize($data['district'] ?? ($explicitGovernorate !== null ? $city : null)),
            'area' => $this->normalize($data['area'] ?? $data['neighborhood'] ?? $data['address_area'] ?? null),
            'street' => $this->normalize($data['street'] ?? $data['address'] ?? null),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];
    }

    private function resolveGovernorate(mixed $explicit, mixed $city, mixed $address): ?string
    {
        $governorates = config('egypt.governorates', []);
        $normalizedExplicit = $this->normalize($explicit);
        $candidates = [(string) $explicit, (string) $city, (string) $address];
        foreach ($governorates as $governorate) {
            $normalizedGovernorate = $this->normalize($governorate);
            foreach ($candidates as $candidate) {
                $normalizedCandidate = $this->normalize($candidate);
                if ($normalizedCandidate && ($normalizedCandidate === $normalizedGovernorate || str_contains($normalizedCandidate, $normalizedGovernorate))) {
                    return $normalizedGovernorate;
                }
            }
        }

        // An explicit governorate always wins. This keeps legacy addresses and
        // administrator-defined English zone names working, while new address
        // forms still benefit from the Arabic city-to-governorate inference.
        if ($normalizedExplicit !== null) {
            return $normalizedExplicit;
        }

        $cityMap = [
            '6 أكتوبر' => 'الجيزة', 'الشيخ زايد' => 'الجيزة', 'العجوزة' => 'الجيزة',
            'مدينة نصر' => 'القاهرة', 'التجمع' => 'القاهرة', 'المعادي' => 'القاهرة', 'حلوان' => 'القاهرة',
            'برج العرب' => 'الإسكندرية', 'العلمين' => 'مطروح', 'العاشر من رمضان' => 'الشرقية',
            'العبور' => 'القليوبية', 'بنها' => 'القليوبية', 'طنطا' => 'الغربية', 'الزقازيق' => 'الشرقية',
            'المنصورة' => 'الدقهلية', 'دمياط الجديدة' => 'دمياط', 'كفر الدوار' => 'البحيرة',
            'سوهاج الجديدة' => 'سوهاج', 'أسيوط الجديدة' => 'أسيوط', 'بني سويف الجديدة' => 'بني سويف',
        ];
        $haystack = $this->normalize(implode(' ', $candidates)) ?? '';
        foreach ($cityMap as $cityName => $governorate) {
            $needle = $this->normalize($cityName);
            if ($needle !== null && str_contains($haystack, $needle)) {
                return $this->normalize($governorate);
            }
        }

        return null;
    }

    /**
     * Coordinates are optional for legacy addresses. If supplied, both values
     * must fall inside Egypt's map bounds used by the web and mobile maps.
     */
    private function hasEgyptianCoordinates(mixed $latitude, mixed $longitude): bool
    {
        if (($latitude === null || $latitude === '') && ($longitude === null || $longitude === '')) {
            return true;
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        // Older mobile clients saved 0,0 when device location was unavailable.
        // It is a placeholder rather than a real location, so keep validating
        // the Egypt country/governorate instead of rejecting a valid address.
        if ($latitude === 0.0 && $longitude === 0.0) {
            return true;
        }

        return $latitude >= 22.0 && $latitude <= 31.7
            && $longitude >= 24.7 && $longitude <= 36.9;
    }

    private function isEgypt(?string $country): bool
    {
        return in_array($country, ['eg', 'egypt', 'مصر', 'جمهورية مصر العربية'], true);
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : mb_strtolower($value);
    }
}
