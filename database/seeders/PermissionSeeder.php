<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Clinical modules not yet implemented (laboratory, pharmacy, payments,
     * ...) still have no permission invented here — each seeds its own
     * when it is built, per ADR-008's action catalog.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'user.view' => 'Voir les utilisateurs',
        'user.create' => 'Créer un utilisateur',
        'user.update' => 'Modifier un utilisateur',
        'user.delete' => 'Supprimer un utilisateur',
        'user.manage' => 'Gérer les comptes, rôles et permissions',

        'patient.view' => 'Voir les patients',
        'patient.create' => 'Créer un patient',
        'patient.update' => 'Modifier un patient',
        'patient.delete' => 'Supprimer un patient',
        'patient.restore' => 'Restaurer un patient',
        'patient.view_deleted' => 'Voir les patients supprimés',
        'patient.force_delete' => 'Supprimer définitivement un patient',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]);
        }
    }
}
