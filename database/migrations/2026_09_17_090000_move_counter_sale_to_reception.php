<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-104 — la vente de médicaments passe de la Pharmacie à la Réception.
 *
 * Retirer les lignes du seeder ne suffit pas sur une base déjà initialisée,
 * et un site en production ne rejoue plus `RolePermissionSeeder` (ADR-064).
 * Le déplacement se fait donc ici, sur les seuls socles de rôle : les
 * exceptions individuelles (`user_permissions`) ne sont jamais touchées —
 * ce sont des décisions prises compte par compte (ADR-033).
 */
return new class extends Migration
{
    private const GRANTED_TO_RECEPTION = [
        'medicines.view',
        'stock.availability.view',
        'pharmacy.counter_sales.create',
    ];

    public function up(): void
    {
        $reception = Role::query()->where('code', 'RECEPTION')->first();
        $pharmacy = Role::query()->where('code', 'PHARMACY')->first();

        if ($reception) {
            $ids = Permission::query()
                ->whereIn('name', self::GRANTED_TO_RECEPTION)
                ->pluck('id');

            // `syncWithoutDetaching` : la Réception garde tout ce qu'elle
            // avait, y compris ce qu'un Super Admin lui a ajouté depuis le
            // portail (ADR-064).
            $reception->permissions()->syncWithoutDetaching($ids);
        }

        $pharmacy?->permissions()->detach(
            Permission::query()->where('name', 'pharmacy.counter_sales.create')->pluck('id'),
        );
    }

    public function down(): void
    {
        $reception = Role::query()->where('code', 'RECEPTION')->first();
        $pharmacy = Role::query()->where('code', 'PHARMACY')->first();

        $reception?->permissions()->detach(
            Permission::query()->where('name', 'pharmacy.counter_sales.create')->pluck('id'),
        );

        $pharmacy?->permissions()->syncWithoutDetaching(
            Permission::query()->where('name', 'pharmacy.counter_sales.create')->pluck('id'),
        );
    }
};
