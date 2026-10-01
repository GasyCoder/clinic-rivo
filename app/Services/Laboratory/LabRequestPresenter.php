<?php

namespace App\Services\Laboratory;

use App\Enums\EpisodePriority;
use App\Models\LabRequest;
use App\Models\LabSample;
use App\Models\Patient;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;

/**
 * ADR-214 — ce qu'un écran du Laboratoire montre d'une demande : son en-tête
 * (patient, origine, réception, numéro de laboratoire, conclusion générale) et
 * ses prélèvements. Écrit une fois pour la paillasse, les étiquettes, le bon
 * d'envoi et la feuille de résultats.
 */
class LabRequestPresenter
{
    private const EXEMPTIONS = [
        LabPaymentClearance::EXEMPT_EMERGENCY => 'Reçue sans attendre le règlement : urgence.',
        LabPaymentClearance::EXEMPT_HOSPITALIZED => 'Reçue sans attendre le règlement : patient hospitalisé.',
    ];

    /** @return array<string, mixed> */
    public function header(LabRequest $request): array
    {
        $request->loadMissing(['episode.patient', 'requestedBy:id,name', 'receivedBy:id,name', 'conclusionBy:id,name']);

        return [
            'uuid' => $request->uuid,
            'lab_number' => $request->lab_number,
            'state' => LabQueue::stateOf($request->loadMissing('items')),
            'cancelled' => $request->cancelled_at !== null,
            'cancel_reason' => $request->cancel_reason,
            'origin' => ParaclinicalRequestPresenter::origin($request),
            'notes' => $request->notes,
            'requested_at' => $request->requested_at,
            'requested_by' => $request->requestedBy?->name,
            'episode_number' => $request->episode->episode_number,
            'episode_uuid' => $request->episode->uuid,
            'emergency' => $request->episode->priority === EpisodePriority::Emergency,
            'received' => $request->received_at !== null,
            'received_at' => $request->received_at,
            'received_by' => $request->receivedBy?->name,
            'payment_exemption' => $request->payment_exemption ? (self::EXEMPTIONS[$request->payment_exemption] ?? null) : null,
            'conclusion' => $request->conclusion,
            'conclusion_at' => $request->conclusion_at,
            'conclusion_by' => $request->conclusionBy?->name,
            'patient' => self::patient($request->episode->patient),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function samples(LabRequest $request): array
    {
        $request->loadMissing(['samples.collectedBy:id,name', 'samples.rejectedBy:id,name']);

        return $request->samples->map(fn (LabSample $sample) => [
            'uuid' => $sample->uuid,
            'sequence' => $sample->sequence,
            'barcode' => $sample->barcode,
            'sample_type' => $sample->sample_type_name_snapshot,
            'tube' => $sample->tube_code_snapshot ? [
                'code' => $sample->tube_code_snapshot,
                'name' => $sample->tube_name_snapshot,
                'color' => $sample->tube_color_snapshot,
                'hex' => $sample->tube_color_hex_snapshot,
            ] : null,
            'notes' => $sample->notes,
            'collected_at' => $sample->collected_at,
            'collected_by' => $sample->collectedBy?->name,
            'rejected' => $sample->rejected_at ? [
                'at' => $sample->rejected_at,
                'by' => $sample->rejectedBy?->name,
                'reason' => $sample->rejection_reason,
            ] : null,
        ])->values()->all();
    }

    /** @return array<string, mixed> Ce qu'il faut pour reconnaître le patient, rien de clinique. */
    public static function patient(Patient $patient): array
    {
        return [
            'uuid' => $patient->uuid,
            'patient_number' => $patient->patient_number,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'sex' => $patient->sex?->value,
            'age' => $patient->birth_date?->age ?? $patient->declared_age,
            'birth_date' => $patient->birth_date?->toDateString(),
        ];
    }
}
