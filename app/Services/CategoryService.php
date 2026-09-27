<?php

namespace App\Services;

use App\Traits\FileManagerTrait;
use App\Traits\GeneratesUniqueSlug;
use Illuminate\Support\Str;

class CategoryService
{
    use FileManagerTrait, GeneratesUniqueSlug;

    public function getAddData(object $request): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $name = $request['name'][array_search('en', $request['lang'])];
        $usesLibraryIcon = $request->input('visual_source') === 'library';

        return [
            'name' => $name,
            'slug' => $this->generateModelUniqueSlug(name: $name, type: 'category'),
            'icon' => $usesLibraryIcon ? null : $this->upload('category/', 'webp', $request->file('image')),
            'medical_icon' => $usesLibraryIcon ? $request->input('medical_icon') : null,
            'icon_storage_type' => !$usesLibraryIcon && $request->hasFile('image') ? $storage : null,
            'parent_id' => $request->get('parent_id', 0),
            'position' => $request['position'] ?? 0,
            'priority' => $request['priority'] ?? 0,
            'home_status' => 1,
        ];
    }

    public function getUpdateData(object $request, object $data): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $image = $request->file('image') ? $this->update('category/', $data['image'], 'webp', $request->file('image')) : $data['icon'];
        $name = $request['name'][array_search('en', $request['lang'])];
        $usesLibraryIcon = $request->input('visual_source') === 'library';

        $result = [
            'name' => $name,
            'slug' => $this->generateModelUniqueSlug(name: $name, type: 'category', id: $data['id']),
            'icon' => $image,
            'medical_icon' => $usesLibraryIcon ? $request->input('medical_icon') : null,
            'icon_storage_type' => $request->has('image') ? $storage : $data['icon_storage_type'],
            'priority' => $request['priority'],
        ];

        if ($request['parent_id']) {
            $result['parent_id'] = $request['parent_id'];
        }
        if ($data['position'] == 0) {
            $result['home_status'] = $request['home_status'] ?? 0;
        }
        return $result;
    }

    public function getSelectOptionHtml(object $data): string
    {
        $output = '<option value="" disabled selected>' . (translate('select_sub_category')) . '</option>';
        foreach ($data as $row) {
            $output .= '<option value="' . $row->id . '">' . $row->defaultName . '</option>';
        }
        return $output;
    }

    public function deleteImages(object $data): bool
    {
        if ($data->childes) {
            foreach ($data->childes as $child) {
                if ($child->childes) {
                    foreach ($child->childes as $item) {
                        if ($item['icon']) {
                            $this->delete('category/' . $item['icon']);
                        }
                    }
                }
                if ($child['icon']) {
                    $this->delete('category/' . $child['icon']);
                }
            }
        }
        if ($data['icon']) {
            $this->delete('category/' . $data['icon']);
        }
        return true;
    }
}
