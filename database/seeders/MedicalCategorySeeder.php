<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Translation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MedicalCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'General Supplies' => 'مستلزمات عامة',
            'Medical Clothing' => 'ملابس طبية',
            'Medical Devices' => 'أجهزة طبية',
            'Filters' => 'فلاتر',
            'First Aid' => 'إسعافات أولية',
            'Surgical Instruments' => 'آلات جراحية',
            'Intensive Care' => 'رعاية مركزة',
            'Medical Gas Networks' => 'شبكات الغاز',
            'Operating Room Supplies' => 'مستلزمات العمليات',
            'Medical Furniture' => 'أثاث طبي',
            'Incubators' => 'حضانات',
            'Dental Supplies' => 'مستلزمات الأسنان',
            'Radiology Devices' => 'أجهزة الأشعة',
            'Physical Therapy' => 'العلاج الطبيعي',
            'Orthopedics' => 'العظام',
            'Laboratory Supplies' => 'المعامل',
            'Prosthetic Devices' => 'الأجهزة التعويضية',
        ];

        foreach ($categories as $english => $arabic) {
            $category = Category::withoutGlobalScopes()->updateOrCreate(
                ['slug' => Str::slug($english)],
                ['name' => $english, 'parent_id' => 0, 'position' => 0, 'priority' => 0]
            );
            $this->translate($category, $arabic);
        }

        $generalSupplies = Category::withoutGlobalScopes()->where('slug', Str::slug('General Supplies'))->firstOrFail();
        foreach ([
            'Syringes' => 'سرنجات',
            'Cotton' => 'قطن',
            'Gauze' => 'شاش',
            'Catheters' => 'قساطر',
            'Cannula' => 'كانيولا',
            'Other Supplies' => 'مستلزمات أخرى',
        ] as $english => $arabic) {
            $category = Category::withoutGlobalScopes()->updateOrCreate(
                ['slug' => 'general-supplies-' . Str::slug($english)],
                ['name' => $english, 'parent_id' => $generalSupplies->id, 'position' => 1, 'priority' => 0]
            );
            $this->translate($category, $arabic);
        }
    }

    private function translate(Category $category, string $arabicName): void
    {
        Translation::updateOrCreate([
            'translationable_type' => Category::class,
            'translationable_id' => $category->id,
            'locale' => 'ar',
            'key' => 'name',
        ], ['value' => $arabicName]);
    }
}
