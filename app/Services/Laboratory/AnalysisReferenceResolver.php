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
        foreach ($this->profiles($patient, $referenceDate) as [$key, $profile]) {
            $value = $analysis->getAttribute("reference_{$key}");
            if (filled($value)) {
                return ['value' => $value, 'profile' => $profile];
            }
        }

        return ['value' => null, 'profile' => $this->fallbackProfile($patient, $referenceDate)];
    }

    /**
     * Les profils à essayer pour ce patient, du plus précis au plus général :
     * enfant (selon le sexe), puis adulte du même sexe, puis la référence
     * générale. ADR-214 — les bornes critiques suivent le même ordre.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function profiles(Patient $patient, CarbonInterface $referenceDate): array
    {
        $age = $patient->birth_date?->diffInYears($referenceDate) ?? $patient->declared_age;
        $isChild = $age !== null && $age < 18;
        $isMale = $patient->sex === PatientSex::Male;

        $candidates = match (true) {
            $isChild && $isMale => [['child_male', 'Enfant · garçon'], ['male', 'Homme']],
            $isChild => [['child_female', 'Enfant · fille'], ['female', 'Femme']],
            $isMale => [['male', 'Homme']],
            default => [['female', 'Femme']],
        };

        $candidates[] = ['general', 'Générale'];

        return $candidates;
    }

    private function fallbackProfile(Patient $patient, CarbonInterface $referenceDate): string
    {
        $age = $patient->birth_date?->diffInYears($referenceDate) ?? $patient->declared_age;

        return ($age !== null && $age < 18) ? 'Enfant' : ($patient->sex === PatientSex::Male ? 'Homme' : 'Femme');
    }
}
