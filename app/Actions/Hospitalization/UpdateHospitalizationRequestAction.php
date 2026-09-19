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
 * complète ici. Corrigée, jamais supprimée : `Auditable` garde l'ancienne et
 * la nouvelle valeur. Un séjour terminé ne se modifie plus.
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

            $locked->hospitalizationRequest->update([
                'reason' => $clean($data['reason'] ?? null),
                'admission_diagnosis' => $clean($data['admission_diagnosis'] ?? null),
                'clinical_summary' => $clean($data['clinical_summary'] ?? null),
                'planned_treatment' => $clean($data['planned_treatment'] ?? null),
                'priority' => ClinicalPriority::from($data['priority']),
                'instructions' => $clean($data['instructions'] ?? null),
            ]);
        });
    }
}
