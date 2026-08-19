<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * The fixed set of principal roles (ADR-006 / CDC §9). NURSE added
     * 2026-08-19, amending ADR-006 — see that ADR for why it's a separate
     * role rather than a MEDICINE subset.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'SUPER_ADMIN' => 'Super Administrateur',
        'ADMINISTRATION' => 'Administration',
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
