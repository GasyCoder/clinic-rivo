<?php

namespace Database\Seeders;

use App\Models\PartnerOrganization;
use Illuminate\Database\Seeder;

class PartnerOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ISPSG', 'TsaraShop'] as $name) {
            PartnerOrganization::query()->firstOrCreate(
                ['normalized_name' => PartnerOrganization::normalize($name)],
                ['name' => $name, 'active' => true],
            );
        }
    }
}
