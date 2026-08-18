<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Foundational permissions only. Clinical modules (patients, laboratory,
     * pharmacy, payments, ...) are not implemented yet, so no permission for
     * them is invented here — each module seeds its own when it is built.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'user.view' => 'Voir les utilisateurs',
        'user.create' => 'Créer un utilisateur',
        'user.update' => 'Modifier un utilisateur',
        'user.delete' => 'Supprimer un utilisateur',
        'user.manage' => 'Gérer les comptes, rôles et permissions',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]);
        }
    }
}
