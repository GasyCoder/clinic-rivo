<?php

namespace App\Policies;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;

/**
 * ADR-170 — l'autorisation **par dossier**, que le RBAC seul ne porte pas.
 *
 * Une permission dit qu'un compte sait opérer dans la clinique ; elle ne dit
 * pas qu'il opère ce patient-là. Jusqu'ici, `can:surgery.intervention.create`
 * suffisait à démarrer l'intervention de n'importe quel dossier — y compris
 * celui d'un confrère, avec un opérateur choisi librement.
 *
 * Les deux conditions sont cumulatives : la permission ouvre le métier,
 * `SurgicalCaseActors` ouvre le dossier. La supervision du bloc
 * (`surgery.update`) reste une porte nommée, jamais un effet de bord.
 */
class SurgicalRequestPolicy
{
    public function __construct(private readonly SurgicalCaseActors $actors) {}

    public function startIntervention(User $user, SurgicalRequest $case): bool
    {
        return $user->can('surgery.intervention.create')
            && $this->actors->canOperate($case, $user);
    }

    /** Corriger l'intervention en cours : même main que celle qui l'a ouverte. */
    public function updateIntervention(User $user, SurgicalRequest $case): bool
    {
        return $user->can('surgery.intervention.update')
            && $this->actors->canOperate($case, $user);
    }

    /**
     * Ouvrir le dossier d'anesthésie. Volontairement **pas** réservé à un
     * anesthésiste déjà affecté : avant ce dossier, personne ne l'est encore,
     * et l'exiger enfermerait la clinique — seul un superviseur pourrait
     * amorcer chaque cas. Le compte qui l'ouvre en devient l'anesthésiste
     * (`CreateAnesthesiaRecordAction`), sauf s'il en désigne explicitement un
     * autre, que la FormRequest restreint déjà au profil ANESTHETIST (ADR-168).
     */
    public function createAnesthesiaRecord(User $user, SurgicalRequest $case): bool
    {
        $case->loadMissing('anesthesiaRecord');

        return $user->can('anesthesia.create')
            && $case->status !== SurgicalRequestStatus::Cancelled
            && $case->anesthesiaRecord === null;
    }

    /**
     * Clore le dossier. La permission reste `surgery.report.validate` : c'est
     * elle qui emportait la clôture avant l'ADR-170, quand valider le compte
     * rendu clôturait le dossier au passage. En inventer une nouvelle aurait
     * retiré à chaque site une capacité qu'il possédait (ADR-101).
     */
    public function complete(User $user, SurgicalRequest $case): bool
    {
        return $user->can('surgery.report.validate')
            && $this->actors->canOperate($case, $user);
    }

    /**
     * Renseigner un temps de la checklist de sécurité. Le droit exact dépend
     * ensuite du rôle confirmé (`SurgicalChecklistRole::permission`) et se
     * vérifie dans l'Action : ici on ouvre seulement la salle à ceux qui y
     * travaillent.
     */
    public function saveChecklist(User $user, SurgicalRequest $case): bool
    {
        if (! $user->can('surgery.preparation.update') && ! $user->can('anesthesia.update')) {
            return false;
        }

        return $this->actors->isTeamMemberOf($case, $user)
            || $this->actors->isSurgeonOf($case, $user)
            || $this->actors->isAnesthetistOf($case, $user)
            || $this->actors->supervises($user);
    }
}
