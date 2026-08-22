<?php

namespace App\Support;

use App\Models\EpisodeOrientation;

class EpisodeQueuePresenter
{
    /** @return array<string, mixed> */
    public function present(EpisodeOrientation $orientation): array
    {
        $episode = $orientation->episode;
        $patient = $episode->patient;

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
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'started_at' => $episode->started_at,
                'administrative_status' => $episode->administrative_status->value,
                'patient' => [
                    'uuid' => $patient->uuid,
                    'patient_number' => $patient->patient_number,
                    'first_name' => $patient->first_name,
                    'last_name' => $patient->last_name,
                    'sex' => $patient->sex->value,
                    'birth_date' => $patient->birth_date?->toDateString(),
                    'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                ],
                'designations' => $episode->billableItems
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
