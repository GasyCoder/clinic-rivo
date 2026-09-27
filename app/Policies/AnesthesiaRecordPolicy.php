<?php

namespace App\Policies;

use App\Models\AnesthesiaRecord;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;

/**
 * ADR-170 — le dossier d'anesthésie appartient à l'anesthésiste affecté.
 *
 * `anesthesia.update` ouvrait jusqu'ici **tous** les dossiers d'anesthésie du
 * site : un anesthésiste pouvait rouvrir et réécrire l'évaluation d'un
 * confrère sans être affecté au cas. La permission reste nécessaire ; elle
 * n'est plus suffisante.
 *
 * Le chirurgien, lui, ne figure nulle part ici : il **lit** la décision
 * d'anesthésie et ne la modifie jamais.
 */
class AnesthesiaRecordPolicy
{
    public function __construct(private readonly SurgicalCaseActors $actors) {}

    public function update(User $user, AnesthesiaRecord $record): bool
    {
        return $this->actors->canWriteAnesthesia($record->surgicalRequest, $user);
    }

    public function validateAssessment(User $user, AnesthesiaRecord $record): bool
    {
        return $user->can('anesthesia.validate')
            && $this->actors->canWriteAnesthesia($record->surgicalRequest, $user);
    }

    /** Prononcer l'autorisation du bloc — le geste le plus engageant de la fiche. */
    public function decideClearance(User $user, AnesthesiaRecord $record): bool
    {
        return $user->can('anesthesia.validate')
            && $this->actors->canWriteAnesthesia($record->surgicalRequest, $user);
    }

    public function validateRecord(User $user, AnesthesiaRecord $record): bool
    {
        return $user->can('anesthesia.validate')
            && $this->actors->canWriteAnesthesia($record->surgicalRequest, $user);
    }
}
