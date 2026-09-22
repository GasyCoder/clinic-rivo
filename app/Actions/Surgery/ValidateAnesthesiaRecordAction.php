<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\AnesthesiaRecord;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — finaliser le dossier d'anesthésie : la conduite peropératoire et
 * le réveil sont documentés, la fiche est verrouillée.
 *
 * Trois faits différents vivent sur cette fiche, et l'un n'a jamais valu
 * l'autre :
 *
 * ```text
 * assessment_validated_at  l'évaluation pré-anesthésique est terminée
 * clearance_status         la décision : le bloc peut-il avoir lieu ?
 * validated_at             ce que fait cette Action : tout est consigné, on ferme
 * ```
 *
 * L'Action n'avait aucune condition : un dossier pouvait être « validé » avant
 * même que l'intervention commence, donc verrouillé alors que la conduite au
 * bloc restait à écrire. Elle s'exécute désormais sous verrou, par
 * l'anesthésiste du dossier, et seulement une fois l'intervention ouverte.
 */
class ValidateAnesthesiaRecordAction
{
    public function __construct(private readonly SurgicalCaseActors $actors) {}

    public function execute(AnesthesiaRecord $record, User $actor): AnesthesiaRecord
    {
        return DB::transaction(function () use ($record, $actor): AnesthesiaRecord {
            $locked = AnesthesiaRecord::query()
                ->with(['surgicalRequest.teamMembers', 'surgicalRequest.intervention'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            if ($locked->validated_at !== null) {
                throw ValidationException::withMessages([
                    'anesthesia' => 'Ce dossier d’anesthésie est déjà validé.',
                ]);
            }

            $case = $locked->surgicalRequest;

            if (! $this->actors->canWriteAnesthesia($case, $actor)) {
                throw ValidationException::withMessages([
                    'anesthesia' => 'Cette fiche se finalise par l’anesthésiste affecté au dossier.',
                ]);
            }

            if ($case->status === SurgicalRequestStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'anesthesia' => 'Cette demande de chirurgie est annulée.',
                ]);
            }

            // Fermer la fiche avant l'incision la verrouillerait alors que la
            // conduite peropératoire reste entièrement à consigner.
            if ($case->intervention === null) {
                throw ValidationException::withMessages([
                    'anesthesia' => 'Le dossier d’anesthésie se finalise une fois l’intervention commencée : la conduite au bloc doit pouvoir y être consignée.',
                ]);
            }

            $locked->validated_by = $actor->getKey();
            $locked->validated_at = now();
            $locked->save();

            return $locked;
        });
    }
}
