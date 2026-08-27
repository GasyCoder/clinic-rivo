<?php

namespace App\Actions\Medicine;

use App\Actions\Pharmacy\SyncInternalDispenseRequestAction;
use App\Enums\PrescriptionStatus;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePrescriptionAction
{
    public function __construct(
        private readonly MedicineStockService $stock,
        private readonly SyncInternalDispenseRequestAction $syncDispenseRequest,
    ) {}

    /**
     * @param  array<int, array{id: int, quantity: int, medication_name?: ?string, dosage?: ?string, frequency?: ?string, duration?: ?string, instructions?: ?string}>  $lines
     */
    public function execute(Prescription $prescription, array $lines, User $actor): Prescription
    {
        return DB::transaction(function () use ($prescription, $lines, $actor): Prescription {
            $prescription = Prescription::query()
                ->lockForUpdate()
                ->findOrFail($prescription->getKey());

            if ($prescription->status !== PrescriptionStatus::Active) {
                throw ValidationException::withMessages([
                    'prescription' => 'Seule une ordonnance active peut être modifiée.',
                ]);
            }

            $existingLines = $prescription->lines()
                ->with('medicine.catalogItem:id,uuid,code,name,unit')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $submittedIds = collect($lines)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

            if ($existingLines->keys()->sort()->values()->all() !== $submittedIds->all()) {
                throw ValidationException::withMessages([
                    'lines' => 'Les lignes envoyées ne correspondent pas à cette ordonnance.',
                ]);
            }

            Medicine::query()
                ->whereIn('id', $existingLines->pluck('medicine_id')->filter()->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $index => $data) {
                $line = $existingLines->get((int) $data['id']);
                $quantity = (int) $data['quantity'];

                if (! $line->medicine) {
                    // A manual (off-catalog) line has no stock to reallocate, so its
                    // quantity is free to change. A true legacy line predating the
                    // Pharmacy catalog link (is_manual_entry false, medicine null)
                    // keeps the original restriction: nothing there was ever
                    // reserved either, but its quantity is not attributable to a
                    // doctor's own off-catalog request and stays frozen to avoid
                    // silently rewriting pre-Pharmacie history.
                    if (! $line->is_manual_entry && $quantity !== $line->quantity) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.quantity" => 'Cette ancienne ligne n’est pas reliée au stock Pharmacie. Retirez puis recréez l’ordonnance pour modifier sa quantité.',
                        ]);
                    }

                    $stockSnapshot = null;
                } else {
                    $stockSnapshot = $this->stock->reallocate(
                        $line->medicine,
                        $line,
                        $quantity,
                        $actor,
                        "lines.{$index}.quantity",
                    );
                }

                $line->update([
                    'quantity' => $quantity,
                    'medication_name' => $line->is_manual_entry && filled($data['medication_name'] ?? null)
                        ? trim($data['medication_name'])
                        : $line->medication_name,
                    'stock_available_at_prescription' => $stockSnapshot['available_before']
                        ?? $line->stock_available_at_prescription,
                    'earliest_expiration_at' => $stockSnapshot['earliest_expiration']
                        ?? $line->earliest_expiration_at,
                    'dosage' => $data['dosage'] ?? null,
                    'frequency' => $data['frequency'] ?? null,
                    'duration' => $data['duration'] ?? null,
                    'instructions' => $data['instructions'] ?? null,
                ]);
            }

            $this->syncDispenseRequest->execute($prescription);

            return $prescription->fresh(['lines.stockReservations', 'pharmacyDispense.lines']);
        });
    }
}
