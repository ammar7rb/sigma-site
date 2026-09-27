<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessCalendarHoliday extends Model
{
    protected $fillable = ['holiday_date', 'name', 'active', 'created_by_admin_id'];

    protected $casts = ['holiday_date' => 'date', 'active' => 'boolean'];
}
