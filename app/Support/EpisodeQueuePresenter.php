<?php

namespace App\Support;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Models\CareOrder;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use Illuminate\Support\Collection;

class EpisodeQueuePresenter
{
    /**
     * SQL mirror of isQueueEligible() below, for ORDER BY clauses: pins a
     * row ahead of arrival order only while it is a still fast-tracked
     * Emergency (Médecine hasn't completed a first consultation for that
     * episode yet). Once eligible, first arrived is first in the list,
     * emergency or not.
     */
    public const PIN_UNSEEN_EMERGENCY_SQL = <<<'SQL'
        CASE WHEN EXISTS (
            SELECT 1 FROM episodes
            WHERE episodes.id = episode_orientations.episode_id
            AND episodes.priority = 'EMERGENCY'
        ) AND NOT EXISTS (
            SELECT 1 FROM episode_orientations eo_medicine_seen
            WHERE eo_medicine_seen.episode_id = episode_orientations.episode_id
            AND eo_medicine_seen.destination_module = 'MEDICINE'
            AND eo_medicine_seen.status = 'COMPLETED'
        ) THEN 0 ELSE 1 END
        SQL;

    public function __construct(private readonly CareWorkflow $careWorkflow) {}

    /**
     * Sequential queue position (1, 2, 3…) in arrival order. A Normal
     * patient gets one immediately; an Emergency patient only joins once
     * Médecine has completed its first consultation for that episode — no
     * longer being fast-tracked ahead of everyone waiting. Computed over
     * the whole active collection (not just the current page) so the
     * numbering stays correct regardless of pagination.
     *
     * @param  Collection<int, EpisodeOrientation>  $orientations
     * @return array<int, int> queue number keyed by EpisodeOrientation::id
     */
    public function assignQueueNumbers(Collection $orientations): array
    {
        $numbers = [];
        $rank = 0;

        foreach ($orientations->sortBy('oriented_at') as $orientation) {
            if (! $this->isQueueEligible($orientation)) {
                continue;
            }

            $numbers[$orientation->getKey()] = ++$rank;
        }

        return $numbers;
    }

    /**
     * Batched equivalent of MedicineDossierPresenter's single-consultation
     * pending-reasons computation, grouped by consultation so a paginated
     * queue never issues one query per row. Only a Médecine orientation
     * carries a Consultation (ADR-035) — other modules' queues (Soins,
     * Chirurgie…) simply get an empty array for every row.
     *
     * @param  Collection<int, EpisodeOrientation>  $orientations
     * @return array<int, array<int, string>> reason labels keyed by EpisodeOrientation::id
     */
    public function pendingReasonsFor(Collection $orientations): array
    {
        $consultationIds = $orientations->pluck('consultation.id')->filter()->values();

        if ($consultationIds->isEmpty()) {
            return [];
        }

        $pendingLab = LabRequest::query()->whereIn('consultation_id', $consultationIds)->with('items')
            ->get()->filter(fn (LabRequest $request) => $request->displayStatus() !== 'COMPLETED')
            ->groupBy('consultation_id')->map->count();
        $pendingImaging = ImagingRequest::query()->whereIn('consultation_id', $consultationIds)->with('items')
            ->get()->filter(fn (ImagingRequest $request) => $request->displayStatus() !== 'COMPLETED')
            ->groupBy('consultation_id')->map->count();
        $pendingCareOrders = CareOrder::query()->whereIn('consultation_id', $consultationIds)->with('items.careRecordProcedures')
            ->get()->filter(fn (CareOrder $order) => $order->displayStatus() !== 'COMPLETED')
            ->groupBy('consultation_id')->map->count();

        $reasons = [];
        foreach ($orientations as $orientation) {
            $consultationId = $orientation->consultation?->id;
            if ($consultationId === null) {
                continue;
            }

            $lab = $pendingLab->get($consultationId, 0);
            $imaging = $pendingImaging->get($consultationId, 0);
            $careOrders = $pendingCareOrders->get($consultationId, 0);

            $reasons[$orientation->getKey()] = array_values(array_filter([
                $lab > 0
                    ? ($lab > 1 ? "{$lab} analyses en attente de résultat" : '1 analyse en attente de résultat')
                    : null,
                $imaging > 0
                    ? ($imaging > 1 ? "{$imaging} examens d’imagerie en attente de compte rendu" : '1 examen d’imagerie en attente de compte rendu')
                    : null,
                $careOrders > 0
                    ? ($careOrders > 1 ? "{$careOrders} ordres de soins en cours" : '1 ordre de soins en cours')
                    : null,
            ]));
        }

        return $reasons;
    }

    private function isQueueEligible(EpisodeOrientation $orientation): bool
    {
        if ($orientation->episode->priority !== EpisodePriority::Emergency) {
            return true;
        }

        return EpisodeOrientation::query()
            ->where('episode_id', $orientation->episode_id)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->where('status', EpisodeOrientationStatus::Completed->value)
            ->exists();
    }

    /**
     * @param  array<int, string>  $pendingReasons  from pendingReasonsFor(), for this one orientation
     * @return array<string, mixed>
     */
    public function present(EpisodeOrientation $orientation, ?int $queueNumber = null, array $pendingReasons = []): array
    {
        $episode = $orientation->episode;
        $patient = $episode->patient;
        $serviceRequests = $episode->relationLoaded('serviceRequests')
            ? $episode->serviceRequests
            : collect();
        $careCompletionMode = $this->careWorkflow->completionMode($episode);

        return [
            'uuid' => $orientation->uuid,
            'queue_number' => $queueNumber,
            'status' => $orientation->status->value,
            'status_label' => $orientation->status->label(),
            'source_module' => $orientation->source_module->value,
            'source_label' => $orientation->source_module->label(),
            'destination_module' => $orientation->destination_module->value,
            'destination_label' => $orientation->destination_module->label(),
            'reason' => $orientation->reason,
            'oriented_at' => $orientation->oriented_at,
            'accepted_at' => $orientation->accepted_at,
            'accepted_by' => $orientation->acceptedBy?->name,
            'has_consultation' => $orientation->relationLoaded('consultation')
                && $orientation->consultation !== null,
            'pending_reasons' => $pendingReasons,
            'is_waiting_on_results' => $pendingReasons !== [],
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'started_at' => $episode->started_at,
                'financial_mode' => $episode->financial_mode?->value,
                'administrative_status' => $episode->administrative_status->value,
                'designation_deferred' => $episode->designation_deferred,
                'care_completion_mode' => $careCompletionMode->value,
                'care_transmission_expected' => $this->careWorkflow->expectsMedicalTransmission($episode),
                'care_vitals_recommended' => $this->careWorkflow->recommendsRoutineVitals($episode),
                'care_requires_allergy_check' => $this->careWorkflow->requiresAllergyCheck($episode),
                'patient' => [
                    'uuid' => $patient->uuid,
                    'patient_number' => $patient->patient_number,
                    'patient_type' => $patient->patient_type->value,
                    'first_name' => $patient->first_name,
                    'last_name' => $patient->last_name,
                    'sex' => $patient->sex->value,
                    'birth_date' => $patient->birth_date?->toDateString(),
                    'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                    'declared_age' => $patient->declared_age,
                    'age' => $patient->birth_date?->age ?? $patient->declared_age,
                ],
                'designations' => $serviceRequests->isNotEmpty()
                    ? $serviceRequests->map(fn ($request) => [
                        'uuid' => $request->uuid,
                        'catalog_item_uuid' => $request->catalog_item_uuid,
                        'code' => $request->catalog_code,
                        'description' => $request->designation,
                        'module' => $request->module->value,
                        'quantity' => $request->quantity,
                        'total_amount' => $request->unit_price === null
                            ? null
                            : Money::fromMinor(Money::multiply($request->quantity, $request->unit_price)),
                        'currency' => $request->currency,
                        'routing_mode' => $request->routing_mode->value,
                        'care_requires_allergy_check' => $request->care_requires_allergy_check,
                        'care_recommends_vitals' => $request->care_recommends_vitals,
                    ])->values()->all()
                    : $episode->billableItems
                        ->filter(fn ($item) => $item->status->value !== 'CANCELLED')
                        ->map(fn ($item) => [
                            'uuid' => $item->uuid,
                            'description' => $item->description,
                            'module' => $item->source_module,
                            'quantity' => $item->quantity,
                            'total_amount' => $item->total_amount,
                            'currency' => $item->currency,
                        ])
                        ->values()
                        ->all(),
            ],
        ];
    }
}
