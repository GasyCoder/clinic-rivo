<?php

namespace App\Services\Billing;

use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeFinancialMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use Illuminate\Validation\ValidationException;

/**
 * Single server-side source of truth for the gross tariff category.
 *
 * A definitive price is resolved from the Episode, never from the legacy
 * Patient.patient_type. A mutual tariff never falls back to Standard.
 * STAFF keeps the Standard gross reference; its separate RH / Finance
 * allocation is handled by StaffFinancialAllocationService.
 */
class CatalogTariffResolver
{
    public function categoryFor(Episode $episode, bool $required = true): ?CatalogTariffCategory
    {
        if ($episode->financial_mode === EpisodeFinancialMode::Mutual) {
            return CatalogTariffCategory::Mutual;
        }

        if (in_array($episode->financial_mode, [
            EpisodeFinancialMode::Self,
            EpisodeFinancialMode::Staff,
            EpisodeFinancialMode::Partner,
        ], true)) {
            return CatalogTariffCategory::Standard;
        }

        // Compatibility is intentionally limited to an immutable request
        // snapshot that already exists. We never infer a new Episode context
        // from Patient.patient_type.
        $legacyCategory = $episode->serviceRequests()
            ->whereNotNull('catalog_tariff_id')
            ->value('tariff_category');

        if ($legacyCategory) {
            return CatalogTariffCategory::tryFrom((string) $legacyCategory);
        }

        if ($required) {
            throw ValidationException::withMessages([
                'financial_mode' => 'Le contexte financier du passage doit être régularisé avant la facturation.',
            ]);
        }

        return null;
    }

    public function relationshipFor(Episode $episode): ?string
    {
        $category = $this->categoryFor($episode, required: false);

        if (! $category) {
            return null;
        }

        return $category === CatalogTariffCategory::Mutual
            ? 'currentMutualTariff'
            : 'currentStandardTariff';
    }

    public function assertEpisodeCanBeBilled(Episode $episode): void
    {
        $this->coverageSnapshot($episode);
    }

    /**
     * Current contractual context. The caller may request a nullable result
     * while opening the clinical route: an emergency route must never be
     * blocked only because the family has not completed the coverage yet.
     *
     * @return array{organization_uuid: ?string, organization_name: ?string, coverage_rate: ?string}
     */
    public function coverageSnapshot(Episode $episode, bool $required = true): array
    {
        if ($episode->financial_mode === EpisodeFinancialMode::Self) {
            return [
                'organization_uuid' => null,
                'organization_name' => null,
                'coverage_rate' => '0.00',
            ];
        }

        if ($episode->financial_mode === EpisodeFinancialMode::Staff) {
            return [
                'organization_uuid' => null,
                'organization_name' => null,
                'coverage_rate' => null,
            ];
        }

        if ($episode->financial_mode === EpisodeFinancialMode::Partner) {
            $episode->loadMissing('partnerCoverage');
            $coverage = $episode->partnerCoverage;

            if (! $coverage) {
                throw ValidationException::withMessages([
                    'financial_mode' => 'Le partenaire du passage doit être complété avant la facturation.',
                ]);
            }

            // No per-prestation coverage scoping exists yet (no
            // Hospitalisation catalogue): the partner covers nothing until
            // that rule is designed, so billing behaves like Self — the
            // patient can pay directly, now or later — rather than being
            // blocked. coverage_rate is a real 0.00, never invented.
            return [
                'organization_uuid' => $coverage->organization_uuid_snapshot,
                'organization_name' => $coverage->organization_name_snapshot,
                'coverage_rate' => '0.00',
            ];
        }

        if ($episode->financial_mode === EpisodeFinancialMode::Mutual) {
            $episode->loadMissing('mutualCoverage');
            $coverage = $episode->mutualCoverage;

            if (! $coverage) {
                throw ValidationException::withMessages([
                    'financial_mode' => 'La couverture mutuelle du passage doit être complétée avant la facturation.',
                ]);
            }

            return [
                'organization_uuid' => $coverage->organization_uuid_snapshot,
                'organization_name' => $coverage->organization_name_snapshot,
                'coverage_rate' => $coverage->coverage_rate_snapshot,
            ];
        }

        $legacy = $episode->serviceRequests()
            ->whereNotNull('catalog_tariff_id')
            ->oldest('id')
            ->first(['mutual_organization_uuid', 'mutual_organization_name', 'coverage_rate']);

        if ($legacy) {
            return [
                'organization_uuid' => $legacy->mutual_organization_uuid,
                'organization_name' => $legacy->mutual_organization_name,
                'coverage_rate' => $legacy->coverage_rate ?? '0.00',
            ];
        }

        if ($required) {
            throw ValidationException::withMessages([
                'financial_mode' => 'Le contexte financier du passage doit être régularisé avant la facturation.',
            ]);
        }

        return [
            'organization_uuid' => null,
            'organization_name' => null,
            'coverage_rate' => null,
        ];
    }

    public function current(
        CatalogItem $item,
        Episode $episode,
        bool $lockForUpdate = false,
    ): ?CatalogTariff {
        $category = $this->categoryFor($episode, required: false);

        if (! $category) {
            return null;
        }

        $query = $item->tariffs()
            ->where('tariff_category', $category->value)
            ->where('active_key', 'CURRENT');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
