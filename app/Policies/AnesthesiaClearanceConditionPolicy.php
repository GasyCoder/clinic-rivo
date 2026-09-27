<?php

namespace App\Policies;

use App\Models\AnesthesiaClearanceCondition;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;

/**
 * ADR-170 — une réserve posée par l'anesthésiste se lève par l'anesthésie.
 *
 * Laisser le bloc cocher lui-même les conditions d'une autorisation
 * « sous conditions » lui rendrait l'autorisation qu'on venait précisément de
 * ne pas lui donner. Lever une réserve est un constat clinique, donc une
 * écriture de la fiche — `anesthesia.update` suffit, comme pour la conduite
 * au bloc, sans exiger `anesthesia.validate` qui appartient aux décisions.
 */
class AnesthesiaClearanceConditionPolicy
{
    public function __construct(private readonly SurgicalCaseActors $actors) {}

    public function resolve(User $user, AnesthesiaClearanceCondition $condition): bool
    {
        $condition->loadMissing('anesthesiaRecord.surgicalRequest');

        return $this->actors->canWriteAnesthesia(
            $condition->anesthesiaRecord->surgicalRequest,
            $user,
        );
    }
}
