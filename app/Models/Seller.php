<?php

namespace App\Models;

use App\Traits\DemoMaskingTrait;
use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $f_name
 * @property string $l_name
 * @property string $country_code
 * @property string $phone
 * @property string|null $phone_verified_at
 * @property string|null $registration_reference
 * @property string $image
 * @property string $email
 * @property string $password
 * @property string $status
 * @property string $bank_name
 * @property string $branch
 * @property string $account_no
 * @property string $holder_name
 * @property string $auth_token
 * @property float $sales_commission_percentage
 * @property float $gst
 * @property string $cm_firebase_token
 * @property string $pos_status
 * @property float $minimum_order_amount
 * @property float $stock_limit
 * @property string $free_delivery_status
 * @property float $free_delivery_over_amount
 * @property string $app_language
 */
class Seller extends Authenticatable
{
    use Notifiable, StorageTrait, DemoMaskingTrait;

    protected $fillable = [
        'f_name',
        'l_name',
        'country_code',
        'phone',
        'phone_verified_at',
        'registration_reference',
        'activation_status',
        'activation_requested_at',
        'activation_approved_at',
        'activation_approved_by_admin_id',
        'email',
        'free_delivery_over_amount',
        'image',
        'password',
        'status',
        'bank_name',
        'branch',
        'account_no',
        'holder_name',
        'auth_token',
        'sales_commission_percentage',
        'gst',
        'cm_firebase_token',
        'pos_status',
        'minimum_order_amount',
        'stock_limit',
        'free_delivery_status',
        'app_language',
    ];

    protected $casts = [
        'id' => 'integer',
        'f_name' => 'string',
        'l_name' => 'string',
        'country_code' => 'string',
        'phone_verified_at' => 'datetime',
        'registration_reference' => 'string',
        'activation_status' => 'string',
        'activation_requested_at' => 'datetime',
        'activation_approved_at' => 'datetime',
        'activation_approved_by_admin_id' => 'integer',
        'orders_count' => 'integer',
        'product_count' => 'integer',
        'pos_status' => 'integer',
    ];

    protected $hidden = [
        'password',
        'registration_reference',
    ];

    public function scopeApproved($query)
    {
        return $query->where(['status' => 'approved']);
    }

    public function shop(): HasOne
    {
        return $this->hasOne(Shop::class, 'seller_id');
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class, 'seller_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    public function product(): HasMany
    {
        return $this->hasMany(Product::class, 'user_id')->where(['added_by' => 'seller']);
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(SellerInsurance::class);
    }

    public function activeInsurance(): HasOne
    {
        return $this->hasOne(SellerInsurance::class)
            ->whereIn('status', [SellerInsurance::STATUS_PAID, SellerInsurance::STATUS_WAIVED])
            ->latestOfMany();
    }

    public function packageSubscriptions(): HasMany
    {
        return $this->hasMany(SellerPackageSubscription::class);
    }

    public function activationCases(): HasMany
    {
        return $this->hasMany(AccountActivationCase::class, 'subject_id')
            ->where('subject_type', AccountActivationCase::SUBJECT_SELLER);
    }

    public function activationTickets(): HasMany
    {
        return $this->hasMany(SellerActivationTicket::class);
    }

    public function activePackageSubscription(): HasOne
    {
        return $this->hasOne(SellerPackageSubscription::class)
            ->where('status', SellerPackageSubscription::STATUS_ACTIVE)
            ->latestOfMany();
    }

    public function packageTransactions(): HasMany
    {
        return $this->hasMany(SellerPackageTransaction::class);
    }

    public function productEntitlements(): HasMany
    {
        return $this->hasMany(SellerProductEntitlement::class);
    }

    public function productPromotions(): HasMany
    {
        return $this->hasMany(SellerProductPromotion::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(SellerWallet::class);
    }

    public function coupon(): HasMany
    {
        return $this->hasMany(Coupon::class, 'seller_id')
            ->where(['coupon_bearer' => 'seller', 'status' => 1])
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'));
    }

    public function getImageFullUrlAttribute(): array
    {
        if ($this->id == 0) {
            return getWebConfig(name: 'company_fav_icon');
        }
        $value = $this->image;
        if (count($this->storage) > 0) {
            $storage = $this->storage->where('key', 'image')->first();
        }
        return $this->storageLink('seller', $value, $storage['value'] ?? 'public');
    }

    protected $appends = ['image_full_url', 'administrative_reference'];

    public function getAdministrativeReferenceAttribute(): string
    {
        return 'V' . $this->id;
    }

    protected static function boot(): void
    {
        parent::boot();
        static::saved(function ($model) {
            if ($model->isDirty('image')) {
                $storage = config('filesystems.disks.default') ?? 'public';
                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'image',
                ], [
                    'value' => $storage,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            cacheRemoveByType(type: 'sellers');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'sellers');
        });
    }

}
