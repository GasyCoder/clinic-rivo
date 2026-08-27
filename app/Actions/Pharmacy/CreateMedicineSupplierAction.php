<?php

namespace App\Actions\Pharmacy;

use App\Models\MedicineSupplier;
use App\Models\User;

class CreateMedicineSupplierAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): MedicineSupplier
    {
        return MedicineSupplier::query()->create([
            'code' => mb_strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'contact_name' => filled($data['contact_name'] ?? null) ? trim($data['contact_name']) : null,
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
            'address' => filled($data['address'] ?? null) ? trim($data['address']) : null,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }
}
