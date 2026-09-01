<?php

namespace App\Actions\Episode;

use App\Actions\Reception\CreateReceptionLabRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeServiceRequest;
use App\Models\User;
use App\Services\Billing\CatalogTariffResolver;
use App\Services\Finance\StaffFinancialAllocationService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Records the immutable routing snapshot for known arrival designations and
 * opens the first operational queue(s). Unknown need is intentionally not
 * represented here by a fake designation; its Care orientation is created by
 * the caller through CreateEpisodeOrientationAction.
 */
class PlanEpisodeRoutingAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly CreateReceptionLabRequestAction $createReceptionLabRequest,
        private readonly CatalogTariffResolver $tariffs,
        private readonly StaffFinancialAllocationService $staffFinancials,
    ) {}

    /**
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $catalogLines
     * @return Collection<int, EpisodeServiceRequest>
     */
    public function execute(Episode $episode, array $catalogLines, User $actor): Collection
    {
        if ($catalogLines === []) {
            throw ValidationException::withMessages([
                'catalog_lines' => 'Utilisez la planification « besoin à définir » lorsqu’aucune désignation n’est connue.',
            ]);
        }

        return DB::transaction(function () use ($episode, $catalogLines, $actor): Collection {
            $lockedEpisode = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());
            $lockedEpisode->loadMissing(['mutualCoverage', 'staffCoverage']);

            if ($lockedEpisode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'Les prestations ne peuvent être planifiées que sur un passage ouvert.',
                ]);
            }

            $normalized = $this->normalizeLines($catalogLines);

            if ($lockedEpisode->service_plan_finalized_at !== null) {
                return $this->replayFinalizedPlan($lockedEpisode, $normalized);
            }

            $category = $this->tariffs->categoryFor($lockedEpisode, required: false);
            $coverage = $this->tariffs->coverageSnapshot($lockedEpisode, required: false);

            $items = CatalogItem::query()
                ->whereIn('uuid', $normalized->keys())
                ->where('type', CatalogItemType::Service->value)
                ->where('billable', true)
                ->where('reception_selectable', true)
                ->whereNotNull('reception_routing_mode')
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            if ($items->count() !== $normalized->count()) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'Une désignation est indisponible ou sans parcours Réception configuré.',
                ]);
            }

            foreach ($normalized as $uuid => $quantity) {
                /** @var CatalogItem $item */
                $item = $items->get($uuid);
                $tariff = $this->tariffs->current(
                    $item,
                    $lockedEpisode,
                    lockForUpdate: true,
                );
                $grossMinor = $tariff
                    ? Money::multiply($quantity, $tariff->amount)
                    : null;
                $coverageMinor = $grossMinor !== null && $coverage['coverage_rate'] !== null
                    ? Money::percentage($grossMinor, $coverage['coverage_rate'])
                    : 0;
                $staffAllocation = $lockedEpisode->financial_mode === EpisodeFinancialMode::Staff
                    && $grossMinor !== null
                        ? $this->staffFinancials->preview($item->staff_coverage_policy, $grossMinor)
                        : null;
                $existing = EpisodeServiceRequest::query()
                    ->where('episode_id', $lockedEpisode->getKey())
                    ->where('catalog_item_id', $item->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->quantity !== $quantity) {
                        throw ValidationException::withMessages([
                            'catalog_lines' => "La désignation {$item->code} est déjà planifiée avec une quantité différente.",
                        ]);
                    }

                    continue;
                }

                EpisodeServiceRequest::query()->create([
                    'episode_id' => $lockedEpisode->getKey(),
                    'catalog_item_id' => $item->getKey(),
                    'catalog_tariff_id' => $tariff?->getKey(),
                    // The historical column is non-null. STANDARD is only a
                    // technical placeholder when an urgent/ambiguous Episode
                    // has no financial context; no tariff or price is resolved
                    // in that case, so this is not a billing fallback.
                    'tariff_category' => $category ?? CatalogTariffCategory::Standard,
                    'staff_coverage_policy' => $item->staff_coverage_policy,
                    'mutual_organization_uuid' => $coverage['organization_uuid'],
                    'mutual_organization_name' => $coverage['organization_name'],
                    'coverage_rate' => $coverage['coverage_rate'],
                    'catalog_item_uuid' => $item->uuid,
                    'catalog_code' => $item->code,
                    'designation' => $item->name,
                    'module' => $item->module,
                    'routing_mode' => $item->reception_routing_mode,
                    'care_requires_allergy_check' => $item->care_requires_allergy_check,
                    'care_recommends_vitals' => $item->care_recommends_vitals,
                    'unit' => $item->unit,
                    'unit_price' => $tariff?->amount,
                    'currency' => $tariff?->currency ?? 'MGA',
                    'quantity' => $quantity,
                    'gross_amount' => $grossMinor !== null ? Money::fromMinor($grossMinor) : null,
                    'coverage_amount' => $staffAllocation
                        ? ($staffAllocation->staffCoveredMinor !== null
                            ? Money::fromMinor($staffAllocation->staffCoveredMinor)
                            : '0.00')
                        : Money::fromMinor($coverageMinor),
                    'staff_covered_amount' => $staffAllocation?->staffCoveredMinor !== null
                        ? Money::fromMinor($staffAllocation->staffCoveredMinor)
                        : ($lockedEpisode->financial_mode === EpisodeFinancialMode::Staff ? null : '0.00'),
                    'staff_block_credit_used' => $staffAllocation?->blockCreditUsedMinor !== null
                        ? Money::fromMinor($staffAllocation->blockCreditUsedMinor)
                        : ($lockedEpisode->financial_mode === EpisodeFinancialMode::Staff ? null : '0.00'),
                    'patient_amount' => $staffAllocation
                        ? ($staffAllocation->patientMinor !== null
                            ? Money::fromMinor($staffAllocation->patientMinor)
                            : null)
                        : ($grossMinor !== null && $coverage['coverage_rate'] !== null
                            ? Money::fromMinor($grossMinor - $coverageMinor)
                            : null),
                    'created_by' => $actor->getKey(),
                ]);
            }

            /** @var Collection<int, EpisodeServiceRequest> $requests */
            $requests = $lockedEpisode->serviceRequests()->lockForUpdate()->get();
            $this->openInitialQueues($lockedEpisode, $requests, $actor);

            $updates = [
                'designation_deferred' => false,
                'service_plan_finalized_at' => now(),
            ];

            if ($lockedEpisode->administrative_status === EpisodeAdministrativeStatus::PendingOrientation) {
                $updates['administrative_status'] = EpisodeAdministrativeStatus::Oriented;
            }

            $lockedEpisode->forceFill($updates)->save();

            return $requests;
        });
    }

    /**
     * Finalize an arrival whose clinical designation is not yet known.
     * No catalog item, billable item or amount is fabricated.
     */
    public function planUnknownNeed(Episode $episode, User $actor): Episode
    {
        return DB::transaction(function () use ($episode, $actor): Episode {
            $lockedEpisode = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());

            if ($lockedEpisode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'defer_designation' => 'Le besoin ne peut être planifié que sur un passage ouvert.',
                ]);
            }

            if ($lockedEpisode->service_plan_finalized_at !== null) {
                if ($lockedEpisode->designation_deferred) {
                    return $lockedEpisode->load('orientations', 'serviceRequests');
                }

                throw ValidationException::withMessages([
                    'defer_designation' => 'Le parcours de ce passage est déjà finalisé avec des désignations.',
                ]);
            }

            $this->createOrientation->execute(
                $lockedEpisode,
                CatalogModule::Reception,
                CatalogModule::Care,
                $actor,
                'Besoin à définir après évaluation aux Soins.',
            );

            $updates = [
                'designation_deferred' => true,
                'service_plan_finalized_at' => now(),
            ];

            if ($lockedEpisode->administrative_status === EpisodeAdministrativeStatus::PendingOrientation) {
                $updates['administrative_status'] = EpisodeAdministrativeStatus::Oriented;
            }

            $lockedEpisode->forceFill($updates)->save();

            return $lockedEpisode->fresh(['orientations', 'serviceRequests']);
        });
    }

    /**
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $lines
     * @return Collection<string, string>
     */
    private function normalizeLines(array $lines): Collection
    {
        $normalized = collect();

        foreach ($lines as $line) {
            $uuid = trim((string) ($line['catalog_item_uuid'] ?? ''));

            if ($uuid === '' || $normalized->has($uuid)) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'Chaque désignation doit être fournie une seule fois avec un UUID valide.',
                ]);
            }

            try {
                $quantity = Money::normalize($line['quantity'] ?? '');
            } catch (InvalidArgumentException|OverflowException) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'La quantité d’une désignation est invalide.',
                ]);
            }

            if (Money::toMinor($quantity) <= 0 || Money::toMinor($quantity) > 999_999) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'La quantité doit être supérieure à zéro et ne pas dépasser 9 999,99.',
                ]);
            }

            $normalized->put($uuid, $quantity);
        }

        return $normalized;
    }

    /**
     * @param  Collection<string, string>  $normalized
     * @return Collection<int, EpisodeServiceRequest>
     */
    private function replayFinalizedPlan(Episode $episode, Collection $normalized): Collection
    {
        if ($episode->designation_deferred) {
            throw ValidationException::withMessages([
                'catalog_lines' => 'Le parcours de ce passage est déjà finalisé avec un besoin à définir.',
            ]);
        }

        /** @var Collection<int, EpisodeServiceRequest> $requests */
        $requests = $episode->serviceRequests()->lockForUpdate()->get();
        $planned = $requests
            ->mapWithKeys(fn (EpisodeServiceRequest $request) => [
                $request->catalog_item_uuid => $request->quantity,
            ])
            ->sortKeys();

        if ($planned->all() !== $normalized->sortKeys()->all()) {
            throw ValidationException::withMessages([
                'catalog_lines' => 'Le parcours de ce passage est déjà finalisé avec une autre sélection.',
            ]);
        }

        return $requests;
    }

    /** @param Collection<int, EpisodeServiceRequest> $requests */
    private function openInitialQueues(Episode $episode, Collection $requests, User $actor): void
    {
        $directDestinations = $requests
            ->map(fn (EpisodeServiceRequest $request) => $request->routing_mode->directDestination())
            ->filter()
            ->unique(fn (CatalogModule $module) => $module->value)
            ->values();

        if ($episode->priority === EpisodePriority::Emergency) {
            $this->createOrientation->execute(
                $episode,
                CatalogModule::Reception,
                CatalogModule::Care,
                $actor,
                'Admission en urgence.',
            );
            $this->createOrientation->execute(
                $episode,
                CatalogModule::Reception,
                CatalogModule::Medicine,
                $actor,
                'Admission en urgence.',
            );

        } else {
            $requiresCare = $requests->contains(
                fn (EpisodeServiceRequest $request) => $request->routing_mode->startsWithCare(),
            );
            $requiresMedicine = $requests->contains(
                fn (EpisodeServiceRequest $request) => $request->routing_mode->requiresMedicine(),
            );

            // Safe aggregation for a physical patient: if any selected
            // service needs Care, Medicine is handed off when Care completes.
            if ($requiresCare) {
                $this->createOrientation->execute(
                    $episode,
                    CatalogModule::Reception,
                    CatalogModule::Care,
                    $actor,
                    'Parcours calculé depuis les désignations d’arrivée.',
                );
            } elseif ($requiresMedicine) {
                $activeCare = $episode->orientations()
                    ->where('destination_module', CatalogModule::Care->value)
                    ->whereIn('status', [
                        EpisodeOrientationStatus::Pending->value,
                        EpisodeOrientationStatus::InProgress->value,
                    ])
                    ->exists();

                if (! $activeCare) {
                    $this->createOrientation->execute(
                        $episode,
                        CatalogModule::Reception,
                        CatalogModule::Medicine,
                        $actor,
                        'Accès direct selon les désignations d’arrivée.',
                    );
                }
            }
        }

        foreach ($directDestinations as $destination) {
            $orientation = $this->createOrientation->execute(
                $episode,
                CatalogModule::Reception,
                $destination,
                $actor,
                "Accès direct {$destination->label()} selon les désignations d’arrivée.",
            );

            if ($destination === CatalogModule::Laboratory) {
                $this->createReceptionLabRequest->execute($episode, $requests, $orientation, $actor);
            }
        }
    }
}
