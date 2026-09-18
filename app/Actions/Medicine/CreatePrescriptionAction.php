<?php

namespace App\Actions\Medicine;

use App\Actions\Pharmacy\CreateInternalDispenseRequestAction;
use App\Enums\ClinicalSuggestionSource;
use App\Enums\PrescriptionLineReviewStatus;
use App\Enums\PrescriptionStatus;
use App\Models\ClinicalProtocol;
use App\Models\Consultation;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Medicine\ClinicPracticeAdvisor;
use App\Services\Pharmacy\MedicineStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePrescriptionAction
{
    public function __construct(
        private readonly MedicineStockService $stock,
        private readonly CreateInternalDispenseRequestAction $createDispenseRequest,
        private readonly ClinicPracticeAdvisor $practice,
    ) {}

    /**
     * A line is either resolved against the Pharmacy catalog (`manual`
     * false, requires `medicine_uuid`) or entered manually because the
     * medicine is absent from the catalog (`manual` true, requires
     * `medication_name`). A manual line never reserves stock and never
     * carries a price — it is created with `medicine_id` null and queued
     * for review by whoever holds `catalog.items.create` (ADR-024), so an
     * ordonnance is never blocked by catalog incompleteness.
     *
     * @param  array<int, array{manual?: bool, medicine_uuid?: string, medication_name?: string, quantity: int, dosage?: ?string, frequency?: ?string, duration?: ?string, instructions?: ?string}>  $lines
     */
    public function execute(Consultation $consultation, array $lines, User $actor): Prescription
    {
        return DB::transaction(function () use ($consultation, $lines, $actor): Prescription {
            $catalogUuids = collect($lines)
                ->filter(fn (array $line) => ! ($line['manual'] ?? false))
                ->pluck('medicine_uuid')
                ->all();
            $lockedMedicines = $this->stock->lockPrescribableMedicines($catalogUuids);

            $origins = [];

            foreach ($lines as $index => $line) {
                if (($line['manual'] ?? false)) {
                    continue;
                }

                if (! $lockedMedicines->has($line['medicine_uuid'])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.medicine_uuid" => 'Ce médicament n’est plus disponible dans le référentiel Pharmacie.',
                    ]);
                }

                $origins[$index] = $this->suggestionOrigin(
                    $consultation,
                    $line['suggestion_source'] ?? null,
                    $line['suggestion_protocol_uuid'] ?? null,
                    $lockedMedicines->get($line['medicine_uuid'])->getKey(),
                    $index,
                );
            }

            // One ordonnance per consultation: adding more lines later (the
            // doctor remembers another drug, or the search takes several
            // submissions) extends the same active prescription instead of
            // spawning a second document to print separately. A cancelled
            // prescription is never reused — a genuinely new one follows.
            $prescription = $consultation->prescriptions()
                ->where('status', PrescriptionStatus::Active->value)
                ->latest('id')
                ->first();

            $prescription ??= $consultation->prescriptions()->create([
                'status' => PrescriptionStatus::Active,
                'prescribed_by' => $actor->getKey(),
                'prescribed_at' => now(),
            ]);

            foreach ($lines as $index => $line) {
                if ($line['manual'] ?? false) {
                    $prescription->lines()->create([
                        'medicine_id' => null,
                        'medication_name' => trim($line['medication_name']),
                        'quantity' => $line['quantity'],
                        'dosage' => $line['dosage'] ?? null,
                        'route' => $line['route'] ?? null,
                        'frequency' => $line['frequency'] ?? null,
                        'duration' => $line['duration'] ?? null,
                        'instructions' => $line['instructions'] ?? null,
                        'is_manual_entry' => true,
                        'catalog_review_status' => PrescriptionLineReviewStatus::Pending,
                    ]);

                    continue;
                }

                $medicine = $lockedMedicines->get($line['medicine_uuid']);
                $prescriptionLine = $prescription->lines()->create([
                    'medicine_id' => $medicine->getKey(),
                    'medication_name' => $medicine->catalogItem->name,
                    'quantity' => $line['quantity'],
                    'dosage' => $line['dosage'] ?? null,
                    // Voie d'administration : « 500 mg orale » n'est pas
                    // « 500 mg IV » (ADR-083).
                    'route' => $line['route'] ?? null,
                    'frequency' => $line['frequency'] ?? null,
                    'duration' => $line['duration'] ?? null,
                    'instructions' => $line['instructions'] ?? null,
                    'suggestion_source' => $origins[$index][0] ?? null,
                    'clinical_protocol_id' => ($origins[$index][1] ?? null)?->getKey(),
                ]);
                $reservation = $this->stock->reserve(
                    $medicine,
                    $prescriptionLine,
                    (int) $line['quantity'],
                    $actor,
                    "lines.{$index}.quantity",
                );

                $prescriptionLine->update([
                    'stock_available_at_prescription' => $reservation['available_before'],
                    'earliest_expiration_at' => $reservation['earliest_expiration'],
                ]);
            }

            $this->createDispenseRequest->execute($prescription);

            return $prescription->fresh(['lines.stockReservations', 'pharmacyDispense.lines']);
        });
    }

    /**
     * ADR-111 — d'où venait la ligne retenue, vérifié plutôt que cru.
     *
     * @return array{0: ?ClinicalSuggestionSource, 1: ?ClinicalProtocol}
     */
    private function suggestionOrigin(Consultation $consultation, ?string $source, ?string $protocolUuid, int $medicineId, int $index): array
    {
        if ($source === ClinicalSuggestionSource::ClinicPractice->value) {
            // La pratique n'a pu proposer qu'un médicament réellement et
            // régulièrement prescrit pour l'un des diagnostics posés ici.
            if (! $this->practice->supportsMedicine($consultation, $medicineId)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.suggestion_source" => 'La pratique de la clinique n’a jamais proposé ce médicament pour les diagnostics posés.',
                ]);
            }

            return [ClinicalSuggestionSource::ClinicPractice, null];
        }

        $protocol = $this->suggestingProtocol($protocolUuid, $medicineId, $index);

        return [$protocol ? ClinicalSuggestionSource::Protocol : null, $protocol];
    }

    /**
     * ADR-111 — une ligne ne se réclame d'un protocole que si ce protocole
     * prescrit réellement ce médicament. Une ligne manuelle n'en a jamais :
     * aucun protocole ne peut proposer un produit absent du référentiel.
     */
    private function suggestingProtocol(?string $uuid, int $medicineId, int $index): ?ClinicalProtocol
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $protocol = ClinicalProtocol::query()
            ->where('uuid', $uuid)
            ->whereHas('lines', fn ($query) => $query->where('medicine_id', $medicineId))
            ->first();

        if (! $protocol) {
            throw ValidationException::withMessages([
                "lines.{$index}.suggestion_protocol_uuid" => 'Cette ligne ne correspond à aucun protocole prescrivant ce médicament.',
            ]);
        }

        return $protocol;
    }
}
