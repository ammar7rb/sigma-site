<?php

namespace App;

use App\Models\ShippingAddress;
use App\Models\CustomerActivationInvoice;
use App\Models\CustomerPurchaseLimitTransaction;
use App\Models\CustomerPurchasePackageSubscription;
use App\Models\Order;
use App\Models\ProductCompare;
use App\Models\Wishlist;
use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable, HasApiTokens, StorageTrait;

    public mixed $email;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'f_name', 'l_name', 'name', 'email', 'password', 'country_code', 'phone', 'image', 'login_medium',
        'is_active', 'social_id', 'is_phone_verified', 'temporary_token', 'referral_code', 'referred_by',
        'street_address', 'country', 'city', 'zip', 'terms_accepted_at', 'privacy_accepted_at',
        'monthly_insurance_started_at', 'monthly_insurance_paid_until', 'monthly_insurance_last_paid_at'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'integer',
        'is_phone_verified' => 'integer',
        'is_email_verified' => 'integer',
        'wallet_balance' => 'float',
        'loyalty_point' => 'float',
        'referred_by' => 'integer',
        'terms_accepted_at' => 'datetime',
        'privacy_accepted_at' => 'datetime',
        'monthly_insurance_started_at' => 'datetime',
        'monthly_insurance_paid_until' => 'datetime',
        'monthly_insurance_last_paid_at' => 'datetime',
    ];

    public function wish_list()
    {
        return $this->hasMany(Wishlist::class, 'customer_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function shipping(): BelongsTo
    {
        return $this->belongsTo(ShippingAddress::class, 'shipping_address');
    }

    public function compare_list()
    {
        return $this->hasMany(ProductCompare::class, 'user_id');
    }

    public function purchasePackageSubscriptions()
    {
        return $this->hasMany(CustomerPurchasePackageSubscription::class, 'customer_id');
    }

    public function activePurchasePackageSubscription()
    {
        return $this->hasOne(CustomerPurchasePackageSubscription::class, 'customer_id')->where('status', 'active');
    }

    public function purchaseLimitTransactions()
    {
        return $this->hasMany(CustomerPurchaseLimitTransaction::class, 'customer_id');
    }

    public function activationInvoices()
    {
        return $this->hasMany(CustomerActivationInvoice::class, 'customer_id');
    }

    public function latestActivationInvoice()
    {
        return $this->hasOne(CustomerActivationInvoice::class, 'customer_id')->latestOfMany();
    }

    public function getImageFullUrlAttribute(): array
    {
        $value = $this->image;
        if (count($this->storage) > 0 && $this->storageConnectionCheck() == 's3') {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'image') {
                    return $this->storageLink('profile', $value, $storage['value']);
                }
            }
        }
        return $this->storageLink('profile', $value, 'public');
    }

    protected $with = ['storage'];
    protected $appends = ['image_full_url'];

    protected static function boot(): void
    {
        parent::boot();
        static::saved(function ($model) {
            if ($model->isDirty('image')) {
                $value = getWebConfig(name: 'storage_connection_type') ?? 'public';
                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'image',
                ], [
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

}
