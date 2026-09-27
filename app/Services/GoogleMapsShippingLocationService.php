<?php

namespace App\Services;

use App\Models\EgyptShippingZoneRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Server-side location resolver for checkout addresses.
 *
 * The browser and mobile app only submit structured text. This class is the
 * only place that talks to Google, keeping the server key private and making
 * it impossible for a customer to set a price from arbitrary coordinates.
 */
class GoogleMapsShippingLocationService
{
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const STATUS_NO_DISPATCH_POINT = 'no_dispatch_point';

    public function resolve(array|object $address): array
    {
        $data = $this->toArray($address);
        $data['country'] = $data['country'] ?? 'Egypt';
        $rateService = app(EgyptShippingRateService::class);
        $governorate = $rateService->governorateFor($data);

        if (!$governorate || !$this->hasAddressDetail($data)) {
            return $this->reviewResult('Please provide the governorate and a detailed address.');
        }

        $shippingZoneRate = $rateService->findRate($data);
        if (!$shippingZoneRate) {
            return [
                'status' => self::STATUS_NO_DISPATCH_POINT,
                'message' => 'A shipping price rule has not been configured for this governorate yet.',
                'governorate' => $governorate,
            ];
        }

        // Customer checkout is priced from the governorate tariff configured by
        // the administration. No map, device location, geocoding request or
        // customer-supplied coordinate is needed to save or quote an address.
        return [
            'status' => self::STATUS_RESOLVED,
            'message' => 'Address accepted for governorate pricing.',
            'governorate' => $governorate,
            'shipping_zone_rate_id' => $shippingZoneRate->id,
            'distance_km' => 0.0,
            'geocoded_address' => $this->addressQuery($data, $governorate),
        ];
    }

    /**
     * Calculate the driving distance for a previously verified address.
     * Returns null rather than estimating a price when Google cannot route it.
     */
    public function routeDistanceKm(EgyptShippingZoneRate $shippingZoneRate, float $latitude, float $longitude): ?float
    {
        $apiKey = (string) getWebConfig(name: 'map_api_key_server');
        if (blank($apiKey)) {
            return null;
        }

        $cacheKey = sprintf(
            'shipping-route-distance:%d:%0.7F:%0.7F:%0.6F:%0.6F',
            $shippingZoneRate->id,
            $shippingZoneRate->dispatch_latitude,
            $shippingZoneRate->dispatch_longitude,
            $latitude,
            $longitude,
        );

        return Cache::remember($cacheKey, now()->addDays(14), function () use ($apiKey, $shippingZoneRate, $latitude, $longitude): ?float {
            $response = Http::timeout(12)
                ->acceptJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'routes.distanceMeters',
                ])
                ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'origin' => $this->waypoint($shippingZoneRate->dispatch_latitude, $shippingZoneRate->dispatch_longitude),
                    'destination' => $this->waypoint($latitude, $longitude),
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                    'units' => 'METRIC',
                ]);

            $meters = $response->successful() ? data_get($response->json(), 'routes.0.distanceMeters') : null;

            return is_numeric($meters) ? round(((float) $meters) / 1000, 3) : null;
        });
    }

    /** Converts a resolution response into safe persisted address attributes. */
    public function persistedLocationFields(array|object $address, array $resolution): array
    {
        $data = $this->toArray($address);
        $isResolved = ($resolution['status'] ?? null) === self::STATUS_RESOLVED;

        return [
            'state' => $resolution['governorate'] ?? $data['state'] ?? $data['governorate'] ?? null,
            'district' => $data['district'] ?? null,
            'area' => $data['area'] ?? null,
            'street' => $data['street'] ?? null,
            'building_number' => $data['building_number'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            // Coordinates supplied by a device are deliberately ignored. Only
            // a server-side geocoding result is trusted for a delivery quote.
            'latitude' => $isResolved ? ($resolution['latitude'] ?? null) : null,
            'longitude' => $isResolved ? ($resolution['longitude'] ?? null) : null,
            'dispatch_point_id' => null,
            'shipping_zone_rate_id' => $isResolved ? $resolution['shipping_zone_rate_id'] : null,
            'distance_km' => $isResolved ? $resolution['distance_km'] : null,
            'geocode_status' => $resolution['status'] ?? self::STATUS_NEEDS_REVIEW,
            'geocoded_address' => $isResolved ? ($resolution['geocoded_address'] ?? null) : null,
            'geocoded_at' => $isResolved ? now() : null,
        ];
    }

    private function resolveUncached(string $apiKey, array $data, string $governorate, EgyptShippingZoneRate $shippingZoneRate): array
    {
        $response = Http::timeout(12)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $this->addressQuery($data, $governorate),
            'components' => 'country:EG',
            'language' => 'ar',
            'key' => $apiKey,
        ]);

        if (!$response->successful()) {
            return [
                'status' => self::STATUS_PROVIDER_UNAVAILABLE,
                'message' => 'Address verification is temporarily unavailable.',
                'governorate' => $governorate,
            ];
        }

        $payload = $response->json();
        if (($payload['status'] ?? null) !== 'OK' || empty($payload['results'])) {
            return $this->reviewResult('The address could not be identified with enough confidence.', $governorate);
        }

        $result = collect($payload['results'])->first(function (array $candidate) use ($governorate): bool {
            return !$candidate['partial_match'] && $this->resultBelongsToGovernorate($candidate, $governorate);
        });
        if (!$result) {
            return $this->reviewResult('The address does not match the selected governorate.', $governorate);
        }

        $latitude = data_get($result, 'geometry.location.lat');
        $longitude = data_get($result, 'geometry.location.lng');
        if (!is_numeric($latitude) || !is_numeric($longitude)
            || !app(EgyptShippingRateService::class)->isEgyptAddress([
                'country' => 'Egypt', 'latitude' => $latitude, 'longitude' => $longitude,
            ])) {
            return $this->reviewResult('The resolved location is outside Egypt.', $governorate);
        }

        $distanceKm = $this->routeDistanceKm($shippingZoneRate, (float) $latitude, (float) $longitude);
        if ($distanceKm === null) {
            return [
                'status' => self::STATUS_PROVIDER_UNAVAILABLE,
                'message' => 'The route distance could not be calculated right now.',
                'governorate' => $governorate,
            ];
        }

        return [
            'status' => self::STATUS_RESOLVED,
            'message' => 'Address verified.',
            'governorate' => $governorate,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'distance_km' => $distanceKm,
            'shipping_zone_rate_id' => $shippingZoneRate->id,
            'geocoded_address' => data_get($result, 'formatted_address'),
        ];
    }

    private function resultBelongsToGovernorate(array $result, string $selectedGovernorate): bool
    {
        $components = collect($result['address_components'] ?? []);
        $governorateNames = $components
            ->filter(fn (array $component): bool => in_array('administrative_area_level_1', $component['types'] ?? [], true))
            ->flatMap(fn (array $component): array => [$component['long_name'] ?? '', $component['short_name'] ?? '']);

        return $governorateNames
            ->map(fn (string $value): ?string => app(EgyptShippingRateService::class)->governorateFor(['state' => $value]))
            ->filter()
            ->contains($selectedGovernorate);
    }

    private function addressQuery(array $data, string $governorate): string
    {
        return collect([
            $data['street'] ?? null,
            $data['building_number'] ?? null,
            $data['area'] ?? null,
            $data['district'] ?? null,
            $data['city'] ?? null,
            $data['address'] ?? null,
            $governorate,
            'Egypt',
        ])->filter(fn ($part): bool => filled($part))->implode(', ');
    }

    private function hasAddressDetail(array $data): bool
    {
        // Additional street/building details improve accuracy but are optional.
        return collect(['city', 'address'])
            ->every(fn (string $key): bool => filled($data[$key] ?? null));
    }

    private function waypoint(float $latitude, float $longitude): array
    {
        return ['location' => ['latLng' => ['latitude' => $latitude, 'longitude' => $longitude]]];
    }

    private function reviewResult(string $message, ?string $governorate = null): array
    {
        return array_filter([
            'status' => self::STATUS_NEEDS_REVIEW,
            'message' => $message,
            'governorate' => $governorate,
        ]);
    }

    private function toArray(array|object $address): array
    {
        if (is_array($address)) {
            return $address;
        }

        return method_exists($address, 'toArray') ? $address->toArray() : get_object_vars($address);
    }
}
