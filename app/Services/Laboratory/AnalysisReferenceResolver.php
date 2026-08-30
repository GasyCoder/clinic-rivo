<?php

namespace App\Services\Laboratory;

use App\Enums\PatientSex;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\Patient;
use Carbon\CarbonInterface;

class AnalysisReferenceResolver
{
    /** @return array<int, array<string, mixed>> */
    public function snapshot(CatalogItem $catalogItem, Patient $patient, CarbonInterface $referenceDate): array
    {
        $catalogItem->loadMissing(['analysisDefinitions' => fn ($query) => $query->where('is_active', true)]);

        return $catalogItem->analysisDefinitions->map(function (AnalysisCatalog $analysis) use ($patient, $referenceDate): array {
            $reference = $this->resolve($analysis, $patient, $referenceDate);

            return [
                'code' => $analysis->code,
                'designation' => $analysis->designation,
                'level' => $analysis->level,
                'result_type' => $analysis->result_type,
                'reference' => $reference['value'],
                'reference_profile' => $reference['profile'],
                'unit' => $analysis->unit,
                'predefined_values' => $analysis->predefined_values ?? [],
            ];
        })->values()->all();
    }

    /** @return array{value: ?string, profile: string} */
    public function resolve(AnalysisCatalog $analysis, Patient $patient, CarbonInterface $referenceDate): array
    {
        $age = $patient->birth_date?->diffInYears($referenceDate) ?? $patient->declared_age;
        $isChild = $age !== null && $age < 18;
        $isMale = $patient->sex === PatientSex::Male;

        $candidates = match (true) {
            $isChild && $isMale => [
                [$analysis->reference_child_male, 'Enfant · garçon'],
                [$analysis->reference_male, 'Homme'],
            ],
            $isChild => [
                [$analysis->reference_child_female, 'Enfant · fille'],
                [$analysis->reference_female, 'Femme'],
            ],
            $isMale => [[$analysis->reference_male, 'Homme']],
            default => [[$analysis->reference_female, 'Femme']],
        };

        $candidates[] = [$analysis->reference_general, 'Générale'];

        foreach ($candidates as [$value, $profile]) {
            if (filled($value)) {
                return ['value' => $value, 'profile' => $profile];
            }
        }

        return ['value' => null, 'profile' => $isChild ? 'Enfant' : ($isMale ? 'Homme' : 'Femme')];
    }
}
