<?php

namespace App\Actions\Hospitalization;

use App\Models\DiagnosticCatalog;
use App\Models\HospitalStay;
use App\Models\HospitalStayDiagnosis;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-147 — poser un diagnostic au terme d'un séjour hospitalier.
 *
 * Il est enregistré sur le séjour, jamais dans la consultation qui a demandé
 * l'hospitalisation : celle-ci est le plus souvent close, et l'ADR-076 refuse
 * toute écriture ordinaire après clôture. Ce diagnostic n'en est pas une
 * correction — c'est la conclusion du séjour, un fait clinique distinct.
 *
 * Même exigence de catalogue que le diagnostic d'une consultation (ADR-035) :
 * une entrée du référentiel ou un libellé saisi, jamais les deux à vide.
 */
class RecordHospitalStayDiagnosisAction
{
    public function execute(
        HospitalStay $stay,
        ?string $description,
        User $actor,
        ?string $catalogUuid = null,
        ?string $notes = null,
    ): HospitalStayDiagnosis {
        return DB::transaction(function () use ($stay, $description, $actor, $catalogUuid, $notes): HospitalStayDiagnosis {
            $locked = HospitalStay::query()->lockForUpdate()->findOrFail($stay->getKey());

            // Le séjour terminé a déjà sa sortie : sa conclusion est signée.
            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'description' => 'Ce séjour est terminé : sa sortie est déjà prononcée.',
                ]);
            }

            $catalog = null;

            if ($catalogUuid) {
                $catalog = DiagnosticCatalog::query()
                    ->where('uuid', $catalogUuid)
                    ->where('is_active', true)
                    ->first();

                if (! $catalog) {
                    throw ValidationException::withMessages([
                        'diagnostic_catalog_uuid' => 'Ce diagnostic du catalogue est introuvable ou désactivé.',
                    ]);
                }

                $already = $locked->diagnoses()
                    ->where('diagnostic_catalog_id', $catalog->getKey())
                    ->exists();

                if ($already) {
                    throw ValidationException::withMessages([
                        'diagnostic_catalog_uuid' => 'Ce diagnostic est déjà posé sur ce séjour.',
                    ]);
                }
            }

            $manual = trim((string) $description);

            if (! $catalog && $manual === '') {
                throw ValidationException::withMessages([
                    'description' => 'Saisissez le libellé du diagnostic.',
                ]);
            }

            return $locked->diagnoses()->create([
                'diagnostic_catalog_id' => $catalog?->getKey(),
                'description' => $catalog?->name ?? $manual,
                'catalog_code_snapshot' => $catalog?->code,
                'catalog_name_snapshot' => $catalog?->name,
                'notes' => trim((string) $notes) ?: null,
                'recorded_by' => $actor->getKey(),
            ]);
        });
    }
}
