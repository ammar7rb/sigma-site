<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Resolves a zone's origin without asking an administrator for coordinates. */
class EgyptDispatchOriginResolver
{
    public function resolve(string $governorate, ?string $district = null, ?string $area = null, ?string $dispatchAddress = null): array
    {
        $dispatchAddress = $this->clean($dispatchAddress);
        $apiKey = (string) getWebConfig(name: 'map_api_key_server');

        // A specific warehouse/depot address takes precedence when Google is
        // configured. This is an admin-only one-time request, never a customer
        // map interaction.
        if ($dispatchAddress && filled($apiKey)) {
            try {
                $response = Http::timeout(12)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => implode(', ', array_filter([$dispatchAddress, $area, $district, $governorate, 'Egypt'])),
                    'components' => 'country:EG',
                    'language' => 'ar',
                    'key' => $apiKey,
                ]);
                $location = $response->successful() ? data_get($response->json(), 'results.0.geometry.location') : null;
                if (is_numeric(data_get($location, 'lat')) && is_numeric(data_get($location, 'lng'))) {
                    return [
                        'dispatch_address' => $dispatchAddress,
                        'dispatch_latitude' => (float) $location['lat'],
                        'dispatch_longitude' => (float) $location['lng'],
                    ];
                }
            } catch (\Throwable) {
                // The governorate centre below keeps settings usable when the
                // configured geocoding provider is unavailable.
            }
        }

        $default = config('egypt.governorate_centers.' . $governorate);
        if ($default) {
            return [
                // Store the actual origin used, so the admin can see when a
                // custom text address fell back to the governorate default.
                'dispatch_address' => 'مركز محافظة ' . $governorate,
                'dispatch_latitude' => (float) $default['latitude'],
                'dispatch_longitude' => (float) $default['longitude'],
            ];
        }

        throw ValidationException::withMessages([
            'governorate' => ['لا توجد نقطة افتراضية لهذه المحافظة. اختر محافظة صحيحة أو أضف عنوان مركز الشحن.'],
        ]);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
