<?php

namespace App\Services\Surgery;

use App\Enums\SurgicalTeamFunction;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTeamMember;
use App\Models\User;

/**
 * ADR-170 — qui est, sur CE dossier, chirurgien ou anesthésiste.
 *
 * Une permission RBAC dit ce qu'un compte sait faire dans le module ; elle ne
 * dit pas que ce compte travaille sur ce patient-là. Les deux sont nécessaires :
 * `anesthesia.update` ouvre le métier, cette classe ouvre le dossier.
 *
 * La supervision reste possible et nommée : un compte qui détient
 * `surgery.update` (chef de bloc, encadrement) peut agir sur un dossier dont il
 * n'est pas membre — mais c'est alors un droit explicitement accordé, pas un
 * effet de bord de `anesthesia.update`.
 */
class SurgicalCaseActors
{
    /** Le droit qui permet d'agir sur un dossier dont on n'est pas membre. */
    public const SUPERVISION = 'surgery.update';

    /** L'anesthésiste du dossier : celui de la fiche, ou celui affecté à l'équipe. */
    public function isAnesthetistOf(SurgicalRequest $case, User $user): bool
    {
        $case->loadMissing(['anesthesiaRecord', 'teamMembers']);

        if ($case->anesthesiaRecord?->anesthetist_id === $user->getKey()) {
            return true;
        }

        return $this->holdsFunction($case, $user, SurgicalTeamFunction::Anesthetist);
    }

    /** Le chirurgien du dossier : l'opérateur principal, ou un chirurgien de l'équipe. */
    public function isSurgeonOf(SurgicalRequest $case, User $user): bool
    {
        if ($case->surgeon_id === $user->getKey()) {
            return true;
        }

        return $this->holdsFunction($case, $user, SurgicalTeamFunction::Surgeon);
    }

    /** Le dossier a-t-il un anesthésiste, quel qu'il soit ? */
    public function hasAnesthetist(SurgicalRequest $case): bool
    {
        $case->loadMissing(['anesthesiaRecord', 'teamMembers']);

        if ($case->anesthesiaRecord?->anesthetist_id !== null) {
            return true;
        }

        return $case->teamMembers->contains(
            fn (SurgicalTeamMember $member) => $member->function === SurgicalTeamFunction::Anesthetist,
        );
    }

    public function isTeamMemberOf(SurgicalRequest $case, User $user): bool
    {
        $case->loadMissing('teamMembers');

        return $case->teamMembers->contains(
            fn (SurgicalTeamMember $member) => $member->user_id === $user->getKey(),
        );
    }

    public function supervises(User $user): bool
    {
        return $user->can(self::SUPERVISION);
    }

    /** Peut écrire dans le dossier d'anesthésie de ce cas. */
    public function canWriteAnesthesia(SurgicalRequest $case, User $user): bool
    {
        return $user->can('anesthesia.update')
            && ($this->isAnesthetistOf($case, $user) || $this->supervises($user));
    }

    /**
     * Peut être l'opérateur de l'intervention. Un compte actif ne suffit pas :
     * le dossier doit le désigner comme chirurgien, ou le compte doit détenir
     * la supervision du bloc.
     */
    public function canOperate(SurgicalRequest $case, User $user): bool
    {
        return $this->isSurgeonOf($case, $user) || $this->supervises($user);
    }

    private function holdsFunction(SurgicalRequest $case, User $user, SurgicalTeamFunction $function): bool
    {
        $case->loadMissing('teamMembers');

        return $case->teamMembers->contains(
            fn (SurgicalTeamMember $member) => $member->user_id === $user->getKey()
                && $member->function === $function,
        );
    }
}
