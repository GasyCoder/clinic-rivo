<?php

namespace App\Actions\Pharmacy;

use App\Models\MedicineCategory;
use App\Models\User;

class CreateMedicineCategoryAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): MedicineCategory
    {
        return MedicineCategory::query()->create([
            'code' => mb_strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }
}
