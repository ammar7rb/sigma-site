<?php

namespace App\Services;

use App\Events\ProductRequestStatusUpdateEvent;
use App\Models\Product;
use App\Models\SellerProductReviewDecision;
use DomainException;

class SellerProductReviewService
{
    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under_review';
    public const APPROVED_PUBLISHED = 'approved_published';
    public const SUSPENDED = 'suspended';
    public const REJECTED = 'rejected';
    public const DELETED = 'deleted';

    /** Send a seller product or content update to the administration review inbox. */
    public function submit(Product $product, array $changedFields = []): Product
    {
        return $this->transition($product, self::SUBMITTED, changedFields: $changedFields);
    }

    public function transition(
        Product $product,
        string $status,
        ?string $reason = null,
        ?int $adminId = null,
        array $changedFields = [],
    ): Product {
        if ($product->added_by !== 'seller') {
            throw new DomainException('seller_product_review_is_only_available_for_seller_products');
        }
        if (!in_array($status, $this->statuses(), true)) {
            throw new DomainException('invalid_seller_product_review_status');
        }
        if (in_array($status, [self::SUSPENDED, self::REJECTED, self::DELETED], true) && trim((string) $reason) === '') {
            throw new DomainException('seller_product_review_reason_is_required');
        }
        if ($status === self::APPROVED_PUBLISHED) {
            app(ProductDateComplianceService::class)->assertPublishable($product);
        }

        $now = now();
        $changedFields = array_values(array_unique(array_filter($changedFields, fn ($field) => is_string($field) && $field !== '')));
        $data = match ($status) {
            self::SUBMITTED => [
                'request_status' => 0, 'status' => 0, 'is_homepage_visible' => 0,
                'seller_review_reason' => null, 'seller_review_changes' => $changedFields, 'denied_note' => null,
            ],
            self::UNDER_REVIEW => [
                'request_status' => 0, 'status' => 0, 'is_homepage_visible' => 0,
                'seller_review_reason' => $reason, 'denied_note' => null,
            ],
            self::APPROVED_PUBLISHED => [
                'request_status' => 1, 'status' => 1, 'seller_review_reason' => $reason,
                'seller_review_changes' => null, 'denied_note' => null,
            ],
            self::SUSPENDED => [
                'request_status' => 1, 'status' => 0, 'featured' => 0, 'is_homepage_visible' => 0,
                'seller_review_reason' => $reason, 'seller_review_changes' => null,
            ],
            self::REJECTED => [
                'request_status' => 2, 'status' => 0, 'featured' => 0, 'is_homepage_visible' => 0,
                'seller_review_reason' => $reason, 'seller_review_changes' => null, 'denied_note' => $reason,
            ],
            self::DELETED => [
                'status' => 0, 'featured' => 0, 'is_homepage_visible' => 0,
                'seller_review_reason' => $reason,
            ],
        };

        $data['seller_review_status'] = $status;
        if ($status === self::SUBMITTED) {
            $data['seller_submitted_at'] = $now;
            $data['seller_reviewed_at'] = null;
            $data['seller_reviewed_by'] = null;
        } elseif ($status !== self::DELETED) {
            $data['seller_reviewed_at'] = $now;
            $data['seller_reviewed_by'] = $adminId;
        }

        $product->forceFill($data)->save();
        SellerProductReviewDecision::create([
            'product_id' => $product->id,
            'seller_id' => $product->user_id,
            'status' => $status,
            'reason' => $reason,
            'changed_fields' => $status === self::SUBMITTED ? $changedFields : null,
            'admin_id' => $adminId,
            'decided_at' => $now,
        ]);

        if (in_array($status, [self::APPROVED_PUBLISHED, self::SUSPENDED, self::REJECTED], true)) {
            $this->notifySeller($product->fresh(['seller']), $status);
        }

        return $product->fresh();
    }

    public function statusFor(Product $product): string
    {
        if ($product->seller_review_status) return $product->seller_review_status;
        if ((int) $product->request_status === 2) return self::REJECTED;
        if ((int) $product->request_status === 1 && (int) $product->status === 1) return self::APPROVED_PUBLISHED;
        if ((int) $product->request_status === 1 && (int) $product->status === 0) return self::SUSPENDED;
        return self::SUBMITTED;
    }

    public function statuses(): array
    {
        return [self::SUBMITTED, self::UNDER_REVIEW, self::APPROVED_PUBLISHED, self::SUSPENDED, self::REJECTED, self::DELETED];
    }

    /** Return human-readable content fields that actually changed in a seller update. */
    public function changedFields(Product $product, array $updates): array
    {
        $labels = [
            'name' => 'اسم المنتج', 'details' => 'الوصف', 'product_type' => 'نوع المنتج',
            'category_id' => 'القسم', 'sub_category_id' => 'القسم الفرعي', 'sub_sub_category_id' => 'القسم الفرعي الثاني',
            'brand_id' => 'العلامة التجارية', 'unit' => 'الوحدة', 'unit_price' => 'سعر الوحدة',
            'current_stock' => 'الكمية', 'minimum_order_qty' => 'الحد الأدنى للطلب', 'tax' => 'الضريبة',
            'tax_type' => 'نوع الضريبة', 'tax_model' => 'نموذج الضريبة', 'discount' => 'الخصم',
            'discount_type' => 'نوع الخصم', 'attributes' => 'الخصائص', 'choice_options' => 'خيارات المنتج',
            'variation' => 'المتغيرات', 'colors' => 'الألوان', 'thumbnail' => 'الصورة الرئيسية',
            'images' => 'صور المنتج', 'color_image' => 'صور الألوان', 'video_url' => 'رابط الفيديو',
            'digital_file_ready' => 'الملف الرقمي', 'production_date' => 'تاريخ الإنتاج', 'expiry_date' => 'تاريخ الصلاحية',
            'meta_title' => 'عنوان محرك البحث', 'meta_description' => 'وصف محرك البحث', 'meta_image' => 'صورة محرك البحث',
            'length' => 'الطول', 'width' => 'العرض', 'height' => 'الارتفاع', 'weight' => 'الوزن',
        ];
        $changed = [];
        foreach ($labels as $field => $label) {
            if (array_key_exists($field, $updates) && $this->comparisonValue($product->getRawOriginal($field)) !== $this->comparisonValue($updates[$field])) {
                $changed[] = $label;
            }
        }
        return $changed;
    }

    private function comparisonValue(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_numeric($value)) return (string) (float) $value;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        if (is_object($value) && method_exists($value, 'format')) return $value->format('Y-m-d H:i:s');
        $value = trim((string) $value);
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE
            ? (json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
            : $value;
    }

    private function notifySeller(Product $product, string $status): void
    {
        $seller = $product->seller;
        if (!$seller?->cm_firebase_token) return;
        $key = match ($status) {
            self::APPROVED_PUBLISHED => 'product_request_approved_message',
            self::REJECTED => 'product_request_rejected_message',
            self::SUSPENDED => 'product_request_suspended_message',
        };
        ProductRequestStatusUpdateEvent::dispatch($key, 'seller', $seller->app_language ?? getDefaultLanguage(), $seller->cm_firebase_token);
    }
}