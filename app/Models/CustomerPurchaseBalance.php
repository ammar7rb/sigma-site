<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPurchaseBalance extends Model
{
    protected $fillable = ['customer_id', 'available_amount', 'held_amount'];

    protected $casts = ['available_amount' => 'float', 'held_amount' => 'float'];
}
