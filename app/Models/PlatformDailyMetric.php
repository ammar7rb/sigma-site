<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDailyMetric extends Model
{
    protected $fillable = ['metric_date', 'channel', 'page_views', 'requests'];
    protected $casts = ['metric_date' => 'date', 'page_views' => 'integer', 'requests' => 'integer'];
}
