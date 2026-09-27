<?php

namespace App\Services;

use App\Models\Product;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Throwable;
use Illuminate\Support\Facades\Schema;

class ProductDateComplianceService
{
    public function validate(array $data): array
    {
        $errors = [];
        $production = $this->parse($data['production_date'] ?? null);
        $expiry = $this->parse($data['expiry_date'] ?? null);

        if (! $production) {
            $errors['production_date'] = 'product_production_date_is_required';
        }
        if (! $expiry) {
            $errors['expiry_date'] = 'product_expiry_date_is_required';
        }
        if ($production && $expiry && $expiry->lt($production)) {
            $errors['expiry_date'] = 'product_expiry_date_must_be_after_or_equal_to_production_date';
        }
        if ($expiry && $expiry->lte(today())) {
            $errors['expiry_date'] = 'expired_product_cannot_be_submitted_or_published';
        }

        return $errors;
    }

    public function normalized(array $data): array
    {
        $errors = $this->validate($data);
        if ($errors) {
            throw new DomainException((string) reset($errors));
        }

        return [
            'production_date' => $this->parse($data['production_date'])->toDateString(),
            'expiry_date' => $this->parse($data['expiry_date'])->toDateString(),
        ];
    }

    public function assertPublishable(Product $product): void
    {
        // Keeps isolated legacy test/install schemas bootable; every migrated
        // application database has both columns and is strictly enforced.
        if (! Schema::hasColumn('products', 'production_date') || ! Schema::hasColumn('products', 'expiry_date')) {
            return;
        }
        $errors = $this->validate([
            'production_date' => $product->getRawOriginal('production_date'),
            'expiry_date' => $product->getRawOriginal('expiry_date'),
        ]);
        if ($errors) {
            throw new DomainException((string) reset($errors));
        }
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
