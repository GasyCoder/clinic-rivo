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

        // module.resource.action (AI_CONTEXT.md) plutôt que patients.* :
        // ces deux permissions doivent pouvoir être restreintes séparément
        // du reste du dossier patient administratif (confidentialité des
        // informations médicales, CDCF client §34.1 règle 9).
        'patients.medical_history.view' => 'Voir les antécédents et allergies',
        'patients.medical_history.manage' => 'Gérer les antécédents et allergies',

        // CDC §12 liste aussi episodes.transfer — non seedée ici : le
        // transfert inter-sites (§19, Phase 7) n'a aucune implémentation
        // dans ce module, à ajouter quand ce flux sera réellement construit.
        'episodes.view' => 'Voir les épisodes',
        'episodes.create' => 'Créer un épisode',
        'episodes.update' => 'Modifier un épisode (orientation, ...)',
        'episodes.cancel' => 'Annuler un épisode',

        // CDC GitHub §15. medical_record.view, laboratory_orders.create,
        // hospitalization.request, surgery.request, transfer.request et
        // medical_discharge.create sont aussi listées là-bas mais non
        // seedées ici : aucune de ces capacités n'est implémentée tant que
        // Laboratoire/Hospitalisation/Chirurgie/Transfert/Sortie médicale
        // (§34) n'existent pas.
        'consultations.view' => 'Voir les consultations',
        'consultations.create' => 'Créer une consultation',
        'consultations.update' => 'Modifier une consultation',
        'consultations.delete' => 'Supprimer une consultation',
        'consultations.restore' => 'Restaurer une consultation',

        'diagnoses.view' => 'Voir les diagnostics',
        'diagnoses.create' => 'Créer un diagnostic',
        'diagnoses.update' => 'Modifier un diagnostic',

        'prescriptions.view' => 'Voir les prescriptions',
        'prescriptions.create' => 'Créer une prescription',
        'prescriptions.update' => 'Modifier une prescription',
        'prescriptions.cancel' => 'Annuler une prescription',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]);
        }
    }
}
