<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EgyptShippingZoneRate extends Model
{
    protected $table = 'egypt_shipping_zone_rates';

    protected $fillable = [
        'country_code',
        'governorate',
        'district',
        'area',
        'street',
        'dispatch_address',
        'dispatch_latitude',
        'dispatch_longitude',
        'normal_cost',
        'sigma_cost',
        'included_distance_km',
        'price_per_km',
        'peak_multiplier',
        'sigma_surcharge',
        'normal_available',
        'sigma_available',
        'status',
    ];

    protected $casts = [
        'normal_cost' => 'float',
        'dispatch_latitude' => 'float',
        'dispatch_longitude' => 'float',
        'sigma_cost' => 'float',
        'included_distance_km' => 'float',
        'price_per_km' => 'float',
        'peak_multiplier' => 'float',
        'sigma_surcharge' => 'float',
        'normal_available' => 'boolean',
        'sigma_available' => 'boolean',
        'status' => 'boolean',
    ];
}
