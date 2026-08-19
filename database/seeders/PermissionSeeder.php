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
        'users.view' => 'Voir les utilisateurs',
        'users.create' => 'Créer un utilisateur',
        'users.update' => 'Modifier un utilisateur',
        'users.delete' => 'Supprimer un utilisateur',
        'users.manage' => 'Gérer les comptes, rôles et permissions',

        'patients.view' => 'Voir les patients',
        'patients.create' => 'Créer un patient',
        'patients.update' => 'Modifier un patient',
        'patients.delete' => 'Supprimer un patient',
        'patients.restore' => 'Restaurer un patient',
        'patients.view_deleted' => 'Voir les patients supprimés',
        'patients.force_delete' => 'Supprimer définitivement un patient',

        // CDC §12 liste aussi episodes.transfer — non seedée ici : le
        // transfert inter-sites (§19, Phase 7) n'a aucune implémentation
        // dans ce module, à ajouter quand ce flux sera réellement construit.
        'episodes.view' => 'Voir les épisodes',
        'episodes.create' => 'Créer un épisode',
        'episodes.update' => 'Modifier un épisode (orientation, ...)',
        'episodes.cancel' => 'Annuler un épisode',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]);
        }
    }
}
