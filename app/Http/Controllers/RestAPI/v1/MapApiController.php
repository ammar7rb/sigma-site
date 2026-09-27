<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Utils\Helpers;
use App\Models\EgyptShippingZoneRate;
use App\Services\GoogleMapsShippingLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class MapApiController extends Controller
{

    /** Governorates currently available to the customer app for delivery. */
    public function shippingGovernorates(): JsonResponse
    {
        $rates = EgyptShippingZoneRate::query()
            ->where('status', true)
            ->whereNotNull('governorate')
            ->whereNotNull('normal_cost')
            ->orderBy('governorate')
            ->get(['governorate', 'normal_cost', 'sigma_cost'])
            ->unique('governorate')
            ->values();

        return response()->json([
            'governorates' => $rates->pluck('governorate'),
            'rates' => $rates,
        ]);
    }

    /**
     * Text-only address verification for the mobile app. No map, marker, or
     * client API key is involved; callers should show the review message when
     * status is not "resolved" and must not show a final delivery price.
     */
    public function resolveShippingAddress(Request $request, GoogleMapsShippingLocationService $locationService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'state' => ['required', 'string'],
            'city' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:500'],
            'district' => ['required', 'string', 'max:120'],
            'area' => ['required', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:180'],
            'building_number' => ['required', 'string', 'max:100'],
            'landmark' => ['nullable', 'string', 'max:180'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        return response()->json($locationService->resolve($validator->validated()));
    }

    private function missingMapApiKeyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Google Maps server API key is not configured.',
        ], 503);
    }

    public function placeApiAutocomplete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search_text' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if (blank(getWebConfig(name: 'map_api_key_server'))) {
            return $this->missingMapApiKeyResponse();
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => getWebConfig(name: 'map_api_key_server'),
            'X-Goog-FieldMask' => '*'
        ])->post('https://places.googleapis.com/v1/places:autocomplete', [
            'input' => $request->input('search_text'),
        ]);

        return response()->json($response->json());
    }

    public function distanceApi(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'origin_lat' => 'required',
            'origin_lng' => 'required',
            'destination_lat' => 'required',
            'destination_lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if (blank(getWebConfig(name: 'map_api_key_server'))) {
            return $this->missingMapApiKeyResponse();
        }

        $origin = [
            "waypoint" => [
                "location" => [
                    "latLng" => [
                        "latitude" => $request['origin_lat'],
                        "longitude" => $request['origin_lng']
                    ]
                ]
            ]
        ];

        $destination = [
            "waypoint" => [
                "location" => [
                    "latLng" => [
                        "latitude" => $request['destination_lat'],
                        "longitude" => $request['destination_lng']
                    ]
                ]
            ]
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => getWebConfig(name: 'map_api_key_server'),
            'X-Goog-FieldMask' => '*'
        ])->post('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', [
            "origins" => $origin,
            "destinations" => $destination,
            "travelMode" => "DRIVE",
            "routingPreference" => "TRAFFIC_AWARE"
        ]);

        return response()->json($response->json());
    }

    public function placeApiDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'placeid' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if (blank(getWebConfig(name: 'map_api_key_server'))) {
            return $this->missingMapApiKeyResponse();
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => getWebConfig(name: 'map_api_key_server'),
            'X-Goog-FieldMask' => '*'
        ])->get('https://places.googleapis.com/v1/places/' . $request['placeid']);

        return response()->json($response->json());
    }

    public function geocode_api(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required',
            'lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        if (blank(getWebConfig(name: 'map_api_key_server'))) {
            return $this->missingMapApiKeyResponse();
        }

        $apiKey = getWebConfig(name: 'map_api_key_server');
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json?latlng=' . $request['lat'] . ',' . $request['lng'] . '&key=' . $apiKey);
        return response()->json($response->json());
    }
}
