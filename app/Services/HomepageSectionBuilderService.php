<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\HomepageLayoutSection;
use App\Models\HomepageLayoutVersion;
use App\Models\Product;
use App\Utils\ProductManager;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomepageSectionBuilderService
{
    public const THEMES = ['default', 'theme_aster'];
    public const SOURCE_MODES = ['automatic', 'manual', 'mixed'];
    public const PRODUCT_SOURCES = ['existing', 'latest', 'best_selling', 'top_rated', 'popular', 'featured', 'paid_ads'];

    public function catalog(string $theme): array
    {
        $this->assertTheme($theme);

        $common = [
            'main_banner' => ['label' => 'Main_Banner', 'type' => 'banner', 'source' => 'existing'],
            'flash_deals' => ['label' => 'Flash_Deals', 'type' => 'content', 'source' => 'existing'],
            'paid_ads' => ['label' => 'Paid_Advertisements', 'type' => 'product', 'source' => 'paid_ads'],
            'featured_products' => ['label' => 'Featured_Products', 'type' => 'product', 'source' => 'featured'],
            'categories' => ['label' => 'Categories', 'type' => 'content', 'source' => 'existing'],
            'clearance_sale' => ['label' => 'Clearance_Sale', 'type' => 'content', 'source' => 'existing'],
            'featured_deals' => ['label' => 'Featured_Deals', 'type' => 'content', 'source' => 'existing'],
            'main_section_banner' => ['label' => 'Main_Section_Banner', 'type' => 'banner', 'source' => 'existing'],
            'latest_products' => ['label' => 'Latest_Products', 'type' => 'product', 'source' => 'latest'],
            'best_selling' => ['label' => 'Best_Selling', 'type' => 'product', 'source' => 'best_selling'],
            'top_rated' => ['label' => 'Top_Rated', 'type' => 'product', 'source' => 'top_rated'],
            'category_products' => ['label' => 'Category_Products', 'type' => 'content', 'source' => 'existing'],
            'deal_of_day' => ['label' => 'Deal_Of_The_Day', 'type' => 'content', 'source' => 'existing'],
            'footer_banners' => ['label' => 'Footer_Banners', 'type' => 'banner', 'source' => 'existing'],
            'brands' => ['label' => 'Brands', 'type' => 'content', 'source' => 'existing'],
            'reliability' => ['label' => 'Company_Reliability', 'type' => 'content', 'source' => 'existing'],
        ];
        $order = $theme === 'theme_aster'
            ? ['main_banner', 'flash_deals', 'paid_ads', 'categories', 'clearance_sale', 'featured_deals', 'featured_products', 'latest_products', 'best_selling', 'top_rated', 'deal_of_day', 'category_products', 'main_section_banner']
            : ['main_banner', 'flash_deals', 'paid_ads', 'featured_products', 'categories', 'featured_deals', 'clearance_sale', 'main_section_banner', 'deal_of_day', 'latest_products', 'best_selling', 'top_rated', 'footer_banners', 'brands', 'category_products', 'reliability'];
        $sections = collect($order)->map(fn (string $key): array => ['key' => $key] + $common[$key])->all();

        return collect($sections)->values()->map(function (array $section, int $index): array {
            return $section + [
                'default_order' => ($index + 1) * 10,
                'default_visible' => true,
            ];
        })->all();
    }

    public function ensureDraft(string $theme, ?int $adminId = null): HomepageLayoutVersion
    {
        $this->assertTables();
        $this->assertTheme($theme);
        $draft = HomepageLayoutVersion::query()->where('theme', $theme)->where('status', HomepageLayoutVersion::STATUS_DRAFT)->latest('version')->first();
        if ($draft) {
            return $draft->load('sections');
        }

        return DB::transaction(function () use ($theme, $adminId): HomepageLayoutVersion {
            $source = HomepageLayoutVersion::query()->where('theme', $theme)->where('status', HomepageLayoutVersion::STATUS_PUBLISHED)->with('sections')->latest('version')->first();
            $version = HomepageLayoutVersion::create([
                'theme' => $theme,
                'version' => $this->nextVersion($theme),
                'status' => HomepageLayoutVersion::STATUS_DRAFT,
                'created_by_admin_id' => $adminId,
            ]);
            $this->copyOrCreateDefaultSections($version, $source);

            return $version->load('sections');
        });
    }

    public function saveDraft(string $theme, array $sections, ?int $adminId, ?string $notes = null): HomepageLayoutVersion
    {
        return DB::transaction(function () use ($theme, $sections, $adminId, $notes): HomepageLayoutVersion {
            $draft = $this->ensureDraft($theme, $adminId);
            $catalog = collect($this->catalog($theme))->keyBy('key');
            foreach ($sections as $key => $input) {
                if (! $catalog->has($key)) {
                    throw new DomainException('unknown_homepage_section');
                }
                $mode = (string) ($input['source_mode'] ?? 'automatic');
                $source = (string) ($input['automatic_source'] ?? $catalog[$key]['source']);
                if (! in_array($mode, self::SOURCE_MODES, true)) {
                    throw new DomainException('invalid_homepage_source_mode');
                }
                if ($catalog[$key]['type'] === 'product' && ! in_array($source, self::PRODUCT_SOURCES, true)) {
                    throw new DomainException('invalid_homepage_product_source');
                }
                $draft->sections()->updateOrCreate(['section_key' => $key], [
                    'is_visible' => (bool) ($input['is_visible'] ?? false),
                    'sort_order' => max(1, (int) ($input['sort_order'] ?? $catalog[$key]['default_order'])),
                    'source_mode' => $mode,
                    'automatic_source' => $source,
                    'product_ids' => $this->normalizeIds($input['product_ids'] ?? []),
                    'banner_ids' => $this->normalizeIds($input['banner_ids'] ?? []),
                    'locale' => in_array(($input['locale'] ?? 'all'), ['all', 'ar', 'en'], true) ? $input['locale'] : 'all',
                    'starts_at' => $input['starts_at'] ?? null,
                    'expires_at' => $input['expires_at'] ?? null,
                    'settings' => ['limit' => max(1, min(50, (int) ($input['limit'] ?? 12)))],
                ]);
            }
            $draft->update(['created_by_admin_id' => $adminId, 'notes' => $notes]);
            $this->clearCaches($theme);

            return $draft->fresh('sections');
        });
    }

    public function publish(string $theme, int $adminId): HomepageLayoutVersion
    {
        return DB::transaction(function () use ($theme, $adminId): HomepageLayoutVersion {
            $draft = $this->ensureDraft($theme, $adminId);
            $errors = $this->validateVersion($draft);
            if ($errors !== []) {
                throw new DomainException(implode(' | ', $errors));
            }
            HomepageLayoutVersion::query()->where('theme', $theme)->where('status', HomepageLayoutVersion::STATUS_PUBLISHED)->update([
                'status' => HomepageLayoutVersion::STATUS_ARCHIVED,
            ]);
            $draft->update([
                'status' => HomepageLayoutVersion::STATUS_PUBLISHED,
                'published_by_admin_id' => $adminId,
                'published_at' => now(),
            ]);
            $this->clearCaches($theme);

            return $draft->fresh('sections');
        });
    }

    public function rollback(string $theme, HomepageLayoutVersion $source, int $adminId): HomepageLayoutVersion
    {
        $this->assertTheme($theme);
        if ($source->theme !== $theme || $source->status === HomepageLayoutVersion::STATUS_DRAFT) {
            throw new DomainException('invalid_homepage_layout_rollback_version');
        }

        return DB::transaction(function () use ($theme, $source, $adminId): HomepageLayoutVersion {
            HomepageLayoutVersion::query()->where('theme', $theme)->where('status', HomepageLayoutVersion::STATUS_PUBLISHED)->update([
                'status' => HomepageLayoutVersion::STATUS_ARCHIVED,
            ]);
            $restored = HomepageLayoutVersion::create([
                'theme' => $theme,
                'version' => $this->nextVersion($theme),
                'status' => HomepageLayoutVersion::STATUS_PUBLISHED,
                'created_by_admin_id' => $adminId,
                'published_by_admin_id' => $adminId,
                'published_at' => now(),
                'notes' => 'Rollback from version '.$source->version,
            ]);
            $this->copyOrCreateDefaultSections($restored, $source->loadMissing('sections'));
            $this->clearCaches($theme);

            return $restored->load('sections');
        });
    }

    public function layout(string $theme, bool $draft = false): array
    {
        $this->assertTheme($theme);
        if (! $this->tablesExist()) {
            return $this->legacyLayout($theme);
        }
        if ($draft) {
            return $this->serialize($this->ensureDraft($theme));
        }

        return Cache::remember('homepage-layout-published-'.$theme, now()->addHours(3), function () use ($theme): array {
            $version = HomepageLayoutVersion::query()->where('theme', $theme)->where('status', HomepageLayoutVersion::STATUS_PUBLISHED)->with('sections')->latest('version')->first();
            return $version ? $this->serialize($version) : $this->legacyLayout($theme);
        });
    }

    public function readiness(string $theme): array
    {
        $this->assertTheme($theme);
        if (! $this->tablesExist()) {
            return ['ready' => false, 'errors' => ['homepage_builder_migrations_are_not_applied']];
        }
        $version = HomepageLayoutVersion::query()->where('theme', $theme)->where('status', HomepageLayoutVersion::STATUS_PUBLISHED)->with('sections')->latest('version')->first();
        if (! $version) {
            return ['ready' => false, 'errors' => ['publish_homepage_layout_before_activating_theme']];
        }
        $errors = $this->validateVersion($version);
        return ['ready' => $errors === [], 'errors' => $errors, 'version' => $version->version];
    }

    /**
     * Product sections that are currently visible on the published homepage.
     * The returned assigned IDs make it possible for the product list to show
     * an administrator exactly where a product is already placed.
     */
    public function productPlacementSections(string $theme): array
    {
        $layout = $this->layout($theme);
        $catalog = collect($this->catalog($theme))->keyBy('key');

        return collect($layout['sections'] ?? [])
            // Paid advertisements keep their own subscription/entitlement flow;
            // editorial placement is available only for the normal product sections.
            ->filter(fn (array $section, string $key): bool => $key !== 'paid_ads'
                && ($catalog[$key]['type'] ?? null) === 'product'
                && (bool) ($section['is_visible'] ?? false))
            ->sortBy('sort_order')
            ->map(fn (array $section, string $key): array => [
                'key' => $key,
                'label' => $catalog[$key]['label'],
                'source_mode' => $section['source_mode'],
                'automatic_source' => $section['automatic_source'],
                'product_ids' => array_map('intval', $section['product_ids'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Saves a product's explicit placement in visible homepage product
     * sections and publishes the resulting layout immediately.  Automatic
     * sections become mixed so their normal product rule continues to work.
     */
    public function placeProductInSections(string $theme, int $productId, array $sectionKeys, int $adminId): HomepageLayoutVersion
    {
        $this->assertTables();
        $allowed = collect($this->productPlacementSections($theme))->pluck('key')->all();
        $sectionKeys = array_values(array_unique(array_intersect($sectionKeys, $allowed)));

        return DB::transaction(function () use ($theme, $productId, $sectionKeys, $adminId, $allowed): HomepageLayoutVersion {
            $draft = $this->ensureDraft($theme, $adminId);
            $sections = $draft->sections->keyBy('section_key');

            foreach ($allowed as $key) {
                /** @var HomepageLayoutSection|null $section */
                $section = $sections->get($key);
                if (! $section) {
                    continue;
                }

                $ids = array_values(array_filter((array) $section->product_ids, fn ($id) => (int) $id !== $productId));
                if (in_array($key, $sectionKeys, true)) {
                    $ids[] = $productId;
                    $section->source_mode = $section->source_mode === 'automatic' ? 'mixed' : $section->source_mode;
                }

                $section->product_ids = array_values(array_unique(array_map('intval', $ids)));

                // A manual product section without any products cannot be
                // published. Removing its final selected product should return
                // that section to its configured automatic source.
                if ($section->source_mode === 'manual' && $section->product_ids === []) {
                    $section->source_mode = 'automatic';
                }
                $section->save();
            }

            $this->clearCaches($theme);
            return $this->publish($theme, $adminId);
        });
    }

    public function resolveProducts(string $theme, string $sectionKey, Collection $fallback, array $layout, bool $recordImpressions = true): Collection
    {
        $section = data_get($layout, 'sections.'.$sectionKey);
        if (! $section || ! $section['is_visible']) {
            return collect();
        }
        $limit = (int) data_get($section, 'settings.limit', 12);
        $mode = $section['source_mode'];
        $manual = $this->manualProducts($section['product_ids'], $limit, $section['automatic_source'] === 'paid_ads');
        $automatic = $this->automaticProducts($section['automatic_source'], $fallback, $sectionKey, $limit);
        $products = match ($mode) {
            'manual' => $manual,
            'mixed' => $manual->concat($automatic)->unique('id')->values(),
            default => $automatic,
        };

        // Approval makes a product searchable. Homepage eligibility is a separate
        // editorial decision made by the administrator during product review.
        $products = $products
            ->filter(fn (Product $product): bool => (int) $product->is_homepage_visible === 1)
            ->take($limit)
            ->values();
        if ($recordImpressions) {
            app(SellerPackagePerformanceService::class)->recordHomepageImpressions($products);
        }

        return $products;
    }

    public function resolveBanners(string $theme, string $sectionKey, Collection $fallback, array $layout): Collection
    {
        $section = data_get($layout, 'sections.'.$sectionKey);
        if (! $section || ! $section['is_visible']) {
            return collect();
        }
        $ids = $section['banner_ids'] ?? [];
        if ($section['source_mode'] === 'automatic' || $ids === []) {
            return $fallback;
        }
        $manual = Banner::query()->where('published', 1)->where('theme', $theme)->whereIn('id', $ids)->get()->sortBy(fn (Banner $banner) => array_search($banner->id, $ids, true))->values();
        return $section['source_mode'] === 'mixed' ? $manual->concat($fallback)->unique('id')->values() : $manual;
    }

    public function versionHistory(string $theme): Collection
    {
        return $this->tablesExist()
            ? HomepageLayoutVersion::query()->where('theme', $theme)->where('status', '!=', HomepageLayoutVersion::STATUS_DRAFT)->latest('version')->limit(20)->get()
            : collect();
    }

    private function validateVersion(HomepageLayoutVersion $version): array
    {
        $errors = [];
        $sections = $version->sections->where('is_visible', true);
        if ($sections->isEmpty()) {
            $errors[] = 'homepage_layout_requires_one_visible_section';
        }
        foreach ($sections as $section) {
            if ($section->starts_at && $section->expires_at && $section->expires_at->lte($section->starts_at)) {
                $errors[] = 'homepage_section_end_must_be_after_start:'.$section->section_key;
            }
            if ($section->source_mode === 'manual' && in_array($section->section_key, $this->productSectionKeys(), true)) {
                $count = Product::active()->homepageVisible()->whereIn('id', $section->product_ids ?? [])->count();
                if ($count === 0) {
                    $errors[] = 'manual_homepage_section_requires_active_products:'.$section->section_key;
                }
            }
            if ($section->source_mode === 'manual' && in_array($section->section_key, $this->bannerSectionKeys(), true)) {
                $count = Banner::query()->where('published', 1)->where('theme', $version->theme)->whereIn('id', $section->banner_ids ?? [])->count();
                if ($count === 0) {
                    $errors[] = 'manual_homepage_banner_section_requires_active_banners:'.$section->section_key;
                }
            }
        }
        $main = $sections->firstWhere('section_key', 'main_banner');
        if ($main && $main->source_mode === 'automatic' && ! Banner::query()->where('theme', $version->theme)->where('published', 1)->where('banner_type', 'Main Banner')->exists()) {
            $errors[] = 'active_main_banner_is_required_for_the_selected_theme';
        }

        return array_values(array_unique($errors));
    }

    private function serialize(HomepageLayoutVersion $version): array
    {
        $language = app()->getLocale();
        $now = now();
        $sections = $version->sections->mapWithKeys(function (HomepageLayoutSection $section) use ($language, $now): array {
            $localeMatches = $section->locale === 'all'
                || ($section->locale === 'ar' && in_array($language, ['ar', 'sa'], true))
                || $section->locale === $language;
            $insidePeriod = (! $section->starts_at || $section->starts_at->lte($now))
                && (! $section->expires_at || $section->expires_at->gt($now));
            return [$section->section_key => [
                'key' => $section->section_key,
                'is_visible' => (bool) $section->is_visible && $localeMatches && $insidePeriod,
                'sort_order' => $section->sort_order,
                'source_mode' => $section->source_mode,
                'automatic_source' => $section->automatic_source,
                'product_ids' => $section->product_ids ?? [],
                'banner_ids' => $section->banner_ids ?? [],
                'locale' => $section->locale,
                'starts_at' => $section->starts_at,
                'expires_at' => $section->expires_at,
                'settings' => $section->settings ?? ['limit' => 12],
            ]];
        })->all();

        return ['theme' => $version->theme, 'version' => $version->version, 'status' => $version->status, 'legacy' => false, 'sections' => $sections];
    }

    private function legacyLayout(string $theme): array
    {
        $sections = collect($this->catalog($theme))->mapWithKeys(fn (array $section) => [$section['key'] => [
            'key' => $section['key'], 'is_visible' => true, 'sort_order' => $section['default_order'],
            'source_mode' => 'automatic', 'automatic_source' => $section['source'], 'product_ids' => [],
            'banner_ids' => [], 'locale' => 'all', 'starts_at' => null, 'expires_at' => null,
            'settings' => ['limit' => 12],
        ]])->all();
        return ['theme' => $theme, 'version' => null, 'status' => 'legacy', 'legacy' => true, 'sections' => $sections];
    }

    private function copyOrCreateDefaultSections(HomepageLayoutVersion $target, ?HomepageLayoutVersion $source): void
    {
        if ($source) {
            foreach ($source->sections as $section) {
                $data = $section->only(['section_key', 'is_visible', 'sort_order', 'source_mode', 'automatic_source', 'product_ids', 'banner_ids', 'locale', 'starts_at', 'expires_at', 'settings']);
                $target->sections()->create($data);
            }
            return;
        }
        foreach ($this->catalog($target->theme) as $section) {
            $target->sections()->create([
                'section_key' => $section['key'], 'is_visible' => $section['default_visible'],
                'sort_order' => $section['default_order'], 'source_mode' => 'automatic',
                'automatic_source' => $section['source'], 'product_ids' => [], 'banner_ids' => [],
                'locale' => 'all', 'settings' => ['limit' => 12],
            ]);
        }
    }

    private function automaticProducts(string $source, Collection $fallback, string $sectionKey, int $limit): Collection
    {
        if ($source === 'existing') {
            return $fallback;
        }
        if ($source === 'paid_ads') {
            $scope = match ($sectionKey) {
                'best_selling' => 'homepage_bestseller',
                'latest_products' => 'homepage_latest',
                'top_rated' => 'homepage_popular',
                default => 'homepage_featured',
            };
            return ProductManager::markHomepagePromotedProducts(ProductManager::getHomepagePromotedProductsQuery($scope)->limit($limit)->get());
        }
        $query = Product::active()->homepageVisible()->with(['seller.shop', 'rating', 'reviews' => fn ($q) => $q->active(), 'clearanceSale' => fn ($q) => $q->active()]);
        $query = match ($source) {
            'latest' => $query->latest('id'),
            'featured' => $query->where('featured', 1)->latest('id'),
            'top_rated' => $query->withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating'),
            'best_selling' => $query->withSum('orderDetails', 'qty')->orderByDesc('order_details_sum_qty'),
            'popular' => $query->withCount('orderDetails')->orderByDesc('order_details_count'),
            default => null,
        };
        return $query ? $query->limit($limit)->get() : $fallback;
    }

    private function manualProducts(array $ids, int $limit, bool $adsOnly): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $query = Product::active()->homepageVisible()->with(['activeHomepagePromotion', 'seller.shop', 'rating', 'reviews' => fn ($q) => $q->active()])->whereIn('id', $ids);
        if ($adsOnly) {
            $query->whereHas('activeHomepagePromotion');
        }
        return $query->get()->sortBy(fn (Product $product) => array_search($product->id, $ids, true))->values()->take($limit)->each(function (Product $product): void {
            if ($product->activeHomepagePromotion) {
                $product->setAttribute('is_homepage_promoted', true);
                $product->setAttribute('advertising_badge_label', data_get($product->activeHomepagePromotion->metadata, 'badge_label') ?: 'Featured_Ad');
            }
        });
    }

    private function normalizeIds(array|string|null $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        return collect($values)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values()->all();
    }

    private function productSectionKeys(): array
    {
        return ['paid_ads', 'featured_products', 'latest_products', 'best_selling', 'top_rated'];
    }

    private function bannerSectionKeys(): array
    {
        return ['main_banner', 'main_section_banner', 'footer_banners'];
    }

    private function nextVersion(string $theme): int
    {
        return ((int) HomepageLayoutVersion::query()->where('theme', $theme)->max('version')) + 1;
    }

    private function clearCaches(string $theme): void
    {
        Cache::forget('homepage-layout-published-'.$theme);
        cacheRemoveByType(type: 'products');
        cacheRemoveByType(type: 'banners');
    }

    private function assertTheme(string $theme): void
    {
        if (! in_array($theme, self::THEMES, true)) {
            throw new DomainException('unsupported_homepage_builder_theme');
        }
    }

    private function assertTables(): void
    {
        if (! $this->tablesExist()) {
            throw new DomainException('homepage_builder_migrations_are_not_applied');
        }
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('homepage_layout_versions') && Schema::hasTable('homepage_layout_sections');
    }
}
