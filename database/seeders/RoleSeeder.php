<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Principal security domains. A role provides only the shared baseline;
     * ProfessionalProfileSeeder classifies the job and proposes additional
     * permissions that are assigned to individual accounts.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'SUPER_ADMIN' => 'Super Administrateur',
        'ADMINISTRATION' => 'Administration / RH',
        'LOGISTICS' => 'Logistique',
        'SUPPORT' => 'Support',
        'MAINTENANCE' => 'Maintenance',
        'RECEPTION' => 'Réception / Caisse',
        'MEDICINE' => 'Médecine',
        'NURSE' => 'Soins paramédicaux',
        'SURGERY' => 'Chirurgie',
        'PHARMACY' => 'Pharmacie',
        'LABORATORY' => 'Laboratoire',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $code => $name) {
            Role::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
