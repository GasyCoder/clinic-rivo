<?php

namespace App\Actions\Hospitalization;

use App\Enums\ClinicalPriority;
use App\Models\HospitalStay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 (amendement) — compléter la demande d'un séjour en cours.
 *
 * La demande part en un clic depuis la consultation ; ce qui manque se
 * complète ici, rubrique par rubrique : seuls les champs reçus sont écrits,
 * un champ absent reste tel quel (omettre n'efface pas, ADR-074). Corrigée,
 * jamais supprimée : `Auditable` garde l'ancienne et la nouvelle valeur. Un
 * séjour terminé ne se modifie plus.
 */
class UpdateHospitalizationRequestAction
{
    /** @param array<string, mixed> $data */
    public function execute(HospitalStay $stay, array $data): void
    {
        DB::transaction(function () use ($stay, $data): void {
            $locked = HospitalStay::query()->with('hospitalizationRequest')->lockForUpdate()->findOrFail($stay->getKey());

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'hospitalization_request' => 'Le séjour est terminé : sa demande ne se modifie plus.',
                ]);
            }

            $clean = static fn (mixed $value): ?string => trim((string) $value) ?: null;

            $changes = [];
            foreach (['reason', 'admission_diagnosis', 'clinical_summary', 'planned_treatment', 'instructions'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $clean($data[$field]);
                }
            }
            if (array_key_exists('priority', $data)) {
                $changes['priority'] = ClinicalPriority::from($data['priority']);
            }

            if ($changes !== []) {
                $locked->hospitalizationRequest->update($changes);
            }
        });
    }
}
