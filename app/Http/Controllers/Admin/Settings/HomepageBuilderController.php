<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\HomepageLayoutVersion;
use App\Models\Product;
use App\Services\HomepageSectionBuilderService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomepageBuilderController extends Controller
{
    public function __construct(private readonly HomepageSectionBuilderService $builder)
    {
    }

    public function index(Request $request): View
    {
        $theme = $this->theme($request);
        $draft = $this->builder->ensureDraft($theme, auth('admin')->id());

        return view('admin-views.system-setup.homepage-builder.index', [
            'theme' => $theme,
            'themes' => HomepageSectionBuilderService::THEMES,
            'catalog' => collect($this->builder->catalog($theme))->keyBy('key'),
            'draft' => $draft,
            'sections' => $draft->sections->keyBy('section_key'),
            'history' => $this->builder->versionHistory($theme),
            'readiness' => $this->builder->readiness($theme),
            'products' => Product::active()->latest('id')->limit(100)->get(['id', 'name']),
            'banners' => Banner::query()->where('theme', $theme)->where('published', 1)->latest('id')->get(['id', 'title', 'banner_type']),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => 'required|in:default,theme_aster',
            'notes' => 'nullable|string|max:2000',
            'sections' => 'required|array',
            'sections.*.is_visible' => 'nullable|boolean',
            'sections.*.sort_order' => 'required|integer|min:1|max:10000',
            'sections.*.source_mode' => 'required|in:automatic,manual,mixed',
            'sections.*.automatic_source' => 'nullable|string|max:40',
            'sections.*.product_ids' => 'nullable|string|max:5000',
            'sections.*.banner_ids' => 'nullable|string|max:5000',
            'sections.*.locale' => 'required|in:all,ar,en',
            'sections.*.starts_at' => 'nullable|date',
            'sections.*.expires_at' => 'nullable|date',
            'sections.*.limit' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $this->builder->saveDraft($validated['theme'], $validated['sections'], auth('admin')->id(), $validated['notes'] ?? null);
            ToastMagic::success(translate('Homepage_draft_saved_successfully'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }

        return back();
    }

    public function preview(Request $request, string $theme): View
    {
        abort_unless(in_array($theme, HomepageSectionBuilderService::THEMES, true), 404);
        $layout = $this->builder->layout($theme, true);
        $resolved = [];
        foreach ($this->builder->catalog($theme) as $definition) {
            $section = data_get($layout, 'sections.'.$definition['key']);
            if ($definition['type'] === 'product') {
                $resolved[$definition['key']] = $this->builder->resolveProducts($theme, $definition['key'], collect(), $layout, false);
            } elseif ($definition['type'] === 'banner') {
                $fallback = Banner::query()->where('theme', $theme)->where('published', 1)
                    ->where('banner_type', $definition['key'] === 'main_banner' ? 'Main Banner' : 'Main Section Banner')->get();
                $resolved[$definition['key']] = $this->builder->resolveBanners($theme, $definition['key'], $fallback, $layout);
            } else {
                $resolved[$definition['key']] = collect();
            }
            $resolved[$definition['key'].'_configuration'] = $section;
        }

        return view('admin-views.system-setup.homepage-builder.preview', [
            'theme' => $theme,
            'layout' => $layout,
            'catalog' => collect($this->builder->catalog($theme))->keyBy('key'),
            'resolved' => $resolved,
        ]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $validated = $request->validate(['theme' => 'required|in:default,theme_aster']);
        try {
            $version = $this->builder->publish($validated['theme'], auth('admin')->id());
            ToastMagic::success(translate('Homepage_layout_published_successfully').' #'.$version->version);
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    public function rollback(Request $request, HomepageLayoutVersion $version): RedirectResponse
    {
        $validated = $request->validate(['theme' => 'required|in:default,theme_aster']);
        try {
            $this->builder->rollback($validated['theme'], $version, auth('admin')->id());
            ToastMagic::success(translate('Homepage_layout_rolled_back_successfully'));
        } catch (DomainException $exception) {
            ToastMagic::error(translate($exception->getMessage()));
        }
        return back();
    }

    private function theme(Request $request): string
    {
        $theme = (string) $request->get('theme', theme_root_path());
        return in_array($theme, HomepageSectionBuilderService::THEMES, true) ? $theme : 'default';
    }
}
