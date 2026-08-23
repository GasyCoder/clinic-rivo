<?php

namespace App\Services\Billing;

use App\Enums\CatalogTariffCategory;
use App\Enums\PatientType;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Patient;
use Illuminate\Validation\ValidationException;

/**
 * Single server-side source of truth for the gross tariff category.
 *
 * A mutual tariff never falls back to Standard: that would silently bill a
 * patient with the wrong price. STAFF keeps the Standard gross tariff while
 * its eventual benefit/share is calculated separately by RH / Finance.
 */
class CatalogTariffResolver
{
    public function categoryFor(Patient $patient): CatalogTariffCategory
    {
        return $patient->patient_type === PatientType::Mutual
            ? CatalogTariffCategory::Mutual
            : CatalogTariffCategory::Standard;
    }

    public function relationshipFor(Patient $patient): string
    {
        return $this->categoryFor($patient) === CatalogTariffCategory::Mutual
            ? 'currentMutualTariff'
            : 'currentStandardTariff';
    }

    public function assertPatientCanBeBilled(Patient $patient): void
    {
        if ($patient->patient_type !== PatientType::Mutual) {
            return;
        }

        $hasActiveCoverage = $patient->relationLoaded('activeMutualCoverage')
            ? $patient->activeMutualCoverage !== null
            : $patient->activeMutualCoverage()->exists();

        if (! $hasActiveCoverage) {
            throw ValidationException::withMessages([
                'patient' => 'La couverture mutuelle active doit être complétée avant la facturation.',
            ]);
        }
    }

    public function current(
        CatalogItem $item,
        Patient $patient,
        bool $lockForUpdate = false,
    ): ?CatalogTariff {
        $query = $item->tariffs()
            ->where('tariff_category', $this->categoryFor($patient)->value)
            ->where('active_key', 'CURRENT');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
