<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * The fixed set of principal roles (ADR-006 / CDC §9), extended by the
     * explicit project decisions for NURSE, LOGISTICS and GUARD. Logistics
     * and guarding are independent operational responsibilities: neither is
     * an alias granting every ADMINISTRATION permission.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'SUPER_ADMIN' => 'Super Administrateur',
        'ADMINISTRATION' => 'Administration / RH',
        'LOGISTICS' => 'Logistique',
        'GUARD' => 'Gardien',
        'RECEPTION' => 'Réception / Caisse',
        'MEDICINE' => 'Médecine',
        'NURSE' => 'Infirmier / Sage-femme',
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
