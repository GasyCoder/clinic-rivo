<?php

namespace App\Services\Pharmacy;

use App\Enums\InvoiceStatus;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Enums\PharmacyDispenseType;
use App\Enums\PrescriptionStatus;
use App\Models\PharmacyDispense;
use App\Models\PharmacyDispenseLine;
use App\Models\Prescription;
use App\Models\PrescriptionLine;

class PharmacyPrescriptionQueueService
{
    /**
     * The queue exposes only reservations still awaiting a future dispensing
     * workflow. It does not infer or expose a payment status.
     *
     * @return array{summary: array<string, int>, prescriptions: array<int, array<string, mixed>>, dispenses: array<int, array<string, mixed>>}
     */
    public function queue(bool $includeLots, bool $includeExpiration): array
    {
        $prescriptions = Prescription::query()
            ->where('status', PrescriptionStatus::Active->value)
            ->whereHas('lines.stockReservations', fn ($query) => $query
                ->where('status', MedicineStockReservationStatus::Reserved->value))
            ->with([
                'consultation.episode.patient:id,uuid,patient_number,first_name,last_name',
                'prescribedBy:id,uuid,name',
                'lines' => fn ($query) => $query
                    ->whereHas('stockReservations', fn ($reservationQuery) => $reservationQuery
                        ->where('status', MedicineStockReservationStatus::Reserved->value))
                    ->with([
                        'medicine.catalogItem:id,uuid,code,name,unit',
                        'stockReservations' => fn ($reservationQuery) => $reservationQuery
                            ->where('status', MedicineStockReservationStatus::Reserved->value)
                            ->with('medicineLot:id,uuid,lot_number,expires_at'),
                    ]),
            ])
            ->orderBy('prescribed_at')
            ->orderBy('id')
            ->get()
            ->map(function (Prescription $prescription) use ($includeLots, $includeExpiration): array {
                $episode = $prescription->consultation?->episode;
                $patient = $episode?->patient;

                $lines = $prescription->lines->map(function (PrescriptionLine $line) use ($includeLots, $includeExpiration): array {
                    $reservations = $line->stockReservations;
                    $lots = $includeLots
                        ? $reservations->map(function ($reservation) use ($includeExpiration): array {
                            $lot = $reservation->medicineLot;

                            return [
                                'uuid' => $lot?->uuid,
                                'lot_number' => $lot?->lot_number,
                                'quantity' => $reservation->remaining_quantity,
                                'expires_at' => $includeExpiration ? $lot?->expires_at?->toDateString() : null,
                            ];
                        })->values()->all()
                        : [];

                    return [
                        'id' => $line->getKey(),
                        'medicine_name' => $line->medicine?->catalogItem?->name ?? $line->medication_name,
                        'medicine_code' => $line->medicine?->catalogItem?->code,
                        'quantity' => $line->quantity,
                        'unit' => $line->medicine?->catalogItem?->unit,
                        'dosage' => $line->dosage,
                        'frequency' => $line->frequency,
                        'duration' => $line->duration,
                        'instructions' => $line->instructions,
                        'reserved_quantity' => (int) $reservations->sum('remaining_quantity'),
                        'lots' => $lots,
                    ];
                })->values();

                return [
                    'uuid' => $prescription->uuid,
                    'prescribed_at' => $prescription->prescribed_at?->toIso8601String(),
                    'prescriber' => $prescription->prescribedBy?->name,
                    'episode' => $episode ? [
                        'uuid' => $episode->uuid,
                        'number' => $episode->episode_number,
                    ] : null,
                    'patient' => $patient ? [
                        'uuid' => $patient->uuid,
                        'number' => $patient->patient_number,
                        'name' => trim($patient->first_name.' '.$patient->last_name),
                    ] : null,
                    'line_count' => $lines->count(),
                    'reserved_quantity' => (int) $lines->sum('reserved_quantity'),
                    'lines' => $lines->all(),
                ];
            })
            ->values();

        $dispenses = PharmacyDispense::query()
            ->whereIn('status', $this->activeDispenseStatuses())
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name',
                'episode:id,uuid,episode_number',
                'prescription.prescribedBy:id,uuid,name',
                'invoice:id,uuid,invoice_number,status,total_amount,paid_amount,balance_amount',
                'lines' => fn ($query) => $query->with([
                    'counterReservations' => fn ($reservationQuery) => $reservationQuery
                        ->where('status', MedicineStockReservationStatus::Reserved->value)
                        ->with('medicineLot:id,uuid,lot_number,expires_at'),
                    'prescriptionLine.stockReservations' => fn ($reservationQuery) => $reservationQuery
                        ->where('status', MedicineStockReservationStatus::Reserved->value)
                        ->with('medicineLot:id,uuid,lot_number,expires_at'),
                ]),
            ])
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get()
            ->map(function (PharmacyDispense $dispense) use ($includeLots, $includeExpiration): array {
                $lines = $dispense->lines->map(function (PharmacyDispenseLine $line) use ($dispense, $includeLots, $includeExpiration): array {
                    $reservations = $dispense->type === PharmacyDispenseType::Internal
                        ? ($line->prescriptionLine?->stockReservations ?? collect())
                        : $line->counterReservations;
                    $lots = $includeLots
                        ? $reservations->map(fn ($reservation): array => [
                            'uuid' => $reservation->medicineLot?->uuid,
                            'lot_number' => $reservation->medicineLot?->lot_number,
                            'quantity' => (int) $reservation->remaining_quantity,
                            'expires_at' => $includeExpiration
                                ? $reservation->medicineLot?->expires_at?->toDateString()
                                : null,
                        ])->values()->all()
                        : [];

                    return [
                        'id' => $line->getKey(),
                        'medicine_name' => $line->medicine_name,
                        'medicine_code' => $line->medicine_code,
                        'unit' => $line->unit,
                        'quantity_requested' => $line->quantity_requested,
                        'quantity_dispensed' => $line->quantity_dispensed,
                        'remaining_quantity' => $line->remainingQuantity(),
                        'lots' => $lots,
                    ];
                })->values();
                $invoiceReady = $dispense->invoice
                    && in_array($dispense->invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Covered], true);

                return [
                    'uuid' => $dispense->uuid,
                    'type' => $dispense->type->value,
                    'type_label' => $dispense->type === PharmacyDispenseType::Internal ? 'Patient interne' : 'Client comptoir',
                    'status' => $dispense->status->value,
                    'status_label' => $dispense->status->label(),
                    'requested_at' => $dispense->requested_at?->toIso8601String(),
                    'customer_name' => $dispense->type === PharmacyDispenseType::Internal
                        ? trim(($dispense->patient?->first_name ?? '').' '.($dispense->patient?->last_name ?? ''))
                        : ($dispense->customer_name ?: 'Client comptoir'),
                    'customer_phone' => $dispense->type === PharmacyDispenseType::External
                        ? $dispense->customer_phone
                        : null,
                    'customer_number' => $dispense->patient?->patient_number,
                    'episode_number' => $dispense->episode?->episode_number,
                    'prescriber' => $dispense->prescription?->prescribedBy?->name ?? $dispense->external_prescriber,
                    'external_prescription_reference' => $dispense->external_prescription_reference,
                    'invoice' => $dispense->invoice ? [
                        'uuid' => $dispense->invoice->uuid,
                        'number' => $dispense->invoice->invoice_number,
                        'status' => $dispense->invoice->status->value,
                        'total_amount' => $dispense->invoice->total_amount,
                        'paid_amount' => $dispense->invoice->paid_amount,
                        'balance_amount' => $dispense->invoice->balance_amount,
                    ] : null,
                    'can_prepare_invoice' => $dispense->status === PharmacyDispenseStatus::AwaitingInvoice,
                    'can_dispense' => $dispense->status->canDispense() && $invoiceReady,
                    'line_count' => $lines->count(),
                    'remaining_quantity' => (int) $lines->sum('remaining_quantity'),
                    'lines' => $lines->all(),
                ];
            })
            ->values();

        return [
            'summary' => [
                'prescriptions' => $prescriptions->count(),
                'lines' => (int) $prescriptions->sum('line_count'),
                'reserved_quantity' => (int) $prescriptions->sum('reserved_quantity'),
                'dispenses' => $dispenses->count(),
                'awaiting_invoice' => $dispenses->where('status', PharmacyDispenseStatus::AwaitingInvoice->value)->count(),
                'awaiting_payment' => $dispenses->where('status', PharmacyDispenseStatus::AwaitingPayment->value)->count(),
                'ready' => $dispenses->whereIn('status', [
                    PharmacyDispenseStatus::Ready->value,
                    PharmacyDispenseStatus::PartiallyDispensed->value,
                ])->count(),
            ],
            'prescriptions' => $prescriptions->all(),
            'dispenses' => $dispenses->all(),
        ];
    }

    public function activeDispenseCount(): int
    {
        return PharmacyDispense::query()
            ->whereIn('status', $this->activeDispenseStatuses())
            ->count();
    }

    /** @return array<int, string> */
    private function activeDispenseStatuses(): array
    {
        return [
            PharmacyDispenseStatus::AwaitingInvoice->value,
            PharmacyDispenseStatus::AwaitingPayment->value,
            PharmacyDispenseStatus::Ready->value,
            PharmacyDispenseStatus::PartiallyDispensed->value,
        ];
    }
}
