<?php

namespace App\Actions\Pharmacy;

use App\Enums\PharmacyDispenseStatus;
use App\Enums\PharmacyDispenseType;
use App\Models\PharmacyDispense;
use App\Models\Prescription;

class CreateInternalDispenseRequestAction
{
    public function execute(Prescription $prescription): ?PharmacyDispense
    {
        $prescription->loadMissing([
            'consultation.episode.patient',
            'lines.medicine.catalogItem',
            'lines.stockReservations',
        ]);
        $stockLines = $prescription->lines->filter(fn ($line) => $line->medicine !== null
            && $line->stockReservations->isNotEmpty());

        if ($stockLines->isEmpty()) {
            return null;
        }

        $episode = $prescription->consultation?->episode;
        $dispense = PharmacyDispense::query()->firstOrCreate(
            ['prescription_id' => $prescription->getKey()],
            [
                'type' => PharmacyDispenseType::Internal,
                'patient_id' => $episode?->patient_id,
                'episode_id' => $episode?->getKey(),
                'status' => PharmacyDispenseStatus::AwaitingInvoice,
                'requested_at' => $prescription->prescribed_at ?? now(),
                'requested_by' => $prescription->prescribed_by,
            ],
        );

        foreach ($stockLines as $line) {
            $dispense->lines()->firstOrCreate(
                ['prescription_line_id' => $line->getKey()],
                [
                    'medicine_id' => $line->medicine_id,
                    'medicine_name' => $line->medicine->catalogItem?->name ?? $line->medication_name,
                    'medicine_code' => $line->medicine->catalogItem?->code,
                    'unit' => $line->medicine->catalogItem?->unit ?? 'unité',
                    'quantity_requested' => $line->quantity,
                    'quantity_dispensed' => 0,
                ],
            );
        }

        return $dispense->load('lines');
    }
}
