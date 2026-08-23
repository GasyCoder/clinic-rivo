<?php

namespace App\Support;

use App\Models\EpisodeOrientation;

class EpisodeQueuePresenter
{
    public function __construct(private readonly CareWorkflow $careWorkflow) {}

    /** @return array<string, mixed> */
    public function present(EpisodeOrientation $orientation): array
    {
        $episode = $orientation->episode;
        $patient = $episode->patient;
        $serviceRequests = $episode->relationLoaded('serviceRequests')
            ? $episode->serviceRequests
            : collect();
        $careCompletionMode = $this->careWorkflow->completionMode($episode);

        return [
            'uuid' => $orientation->uuid,
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
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'started_at' => $episode->started_at,
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
