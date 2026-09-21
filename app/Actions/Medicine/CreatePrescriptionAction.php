<?php

namespace App\Actions\Medicine;

use App\Actions\Pharmacy\CreateInternalDispenseRequestAction;
use App\Enums\ClinicalSuggestionSource;
use App\Enums\PrescriptionLineReviewStatus;
use App\Enums\PrescriptionStatus;
use App\Models\ClinicalProtocol;
use App\Models\Consultation;
use App\Models\HospitalStay;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Medicine\ClinicalProtocolMatcher;
use App\Services\Medicine\ClinicPracticeAdvisor;
use App\Services\Pharmacy\MedicineStockService;
use App\Support\Hospitalization\StayOrderContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePrescriptionAction
{
    public function __construct(
        private readonly MedicineStockService $stock,
        private readonly CreateInternalDispenseRequestAction $createDispenseRequest,
        private readonly ClinicPracticeAdvisor $practice,
        private readonly ClinicalProtocolMatcher $protocols,
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
                    fn (int $medicineId): bool => $this->practice->supportsMedicine($consultation, $medicineId),
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
                'episode_id' => $consultation->episode_id,
                'status' => PrescriptionStatus::Active,
                'prescribed_by' => $actor->getKey(),
                'prescribed_at' => now(),
            ]);

            $this->addLines($prescription, $lines, $lockedMedicines, $origins, $actor);

            $this->createDispenseRequest->execute($prescription);

            return $prescription->fresh(['lines.stockReservations', 'pharmacyDispense.lines']);
        });
    }

    /**
     * ADR-162 — l'ordonnance écrite depuis le séjour, sans consultation.
     *
     * Chaque ordonnance du séjour est un document nouveau (celle de lundi
     * n'est pas celle de mercredi) : aucune n'est étendue après coup, si bien
     * qu'une ligne ne rejoint jamais une délivrance déjà facturée. Réservation
     * FEFO, lignes manuelles et demande de délivrance sont celles d'une
     * consultation ; seule la délivrance, au service, n'attend pas le
     * règlement (`PharmacyDispense::isWardDispense()`).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function executeForStay(HospitalStay $stay, array $lines, User $actor): Prescription
    {
        return DB::transaction(function () use ($stay, $lines, $actor): Prescription {
            $context = StayOrderContext::lock($stay, 'prescription');

            $catalogUuids = collect($lines)
                ->filter(fn (array $line) => ! ($line['manual'] ?? false))
                ->pluck('medicine_uuid')
                ->all();
            $lockedMedicines = $this->stock->lockPrescribableMedicines($catalogUuids);

            // ADR-163 — une ligne proposée par un protocole ou par la pratique
            // de la clinique garde son origine, vérifiée contre les diagnostics
            // du passage plutôt que crue (ADR-111).
            $diagnosisIds = null;
            $origins = [];

            foreach ($lines as $index => $line) {
                if ($line['manual'] ?? false) {
                    continue;
                }

                if (! $lockedMedicines->has($line['medicine_uuid'])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.medicine_uuid" => 'Ce médicament n’est plus disponible dans le référentiel Pharmacie.',
                    ]);
                }

                if (filled($line['suggestion_source'] ?? null) || filled($line['suggestion_protocol_uuid'] ?? null)) {
                    $diagnosisIds ??= $this->protocols->stayContext($context->stay)['diagnosis_catalog_ids'];
                    $origins[$index] = $this->suggestionOrigin(
                        fn (int $medicineId): bool => $this->practice->supportsMedicineFor($diagnosisIds, $medicineId),
                        $line['suggestion_source'] ?? null,
                        $line['suggestion_protocol_uuid'] ?? null,
                        $lockedMedicines->get($line['medicine_uuid'])->getKey(),
                        $index,
                    );
                }
            }

            $prescription = Prescription::query()->create([
                'episode_id' => $context->episode->getKey(),
                'hospital_stay_id' => $context->stay->getKey(),
                'consultation_id' => null,
                'status' => PrescriptionStatus::Active,
                'prescribed_by' => $actor->getKey(),
                'prescribed_at' => now(),
            ]);

            $this->addLines($prescription, $lines, $lockedMedicines, $origins, $actor);
            $this->createDispenseRequest->execute($prescription);

            return $prescription->fresh(['lines.stockReservations', 'pharmacyDispense.lines']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array{0: ?ClinicalSuggestionSource, 1: ?ClinicalProtocol}>  $origins
     */
    private function addLines(Prescription $prescription, array $lines, $lockedMedicines, array $origins, User $actor): void
    {
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
    }

    /**
     * ADR-111 — d'où venait la ligne retenue, vérifié plutôt que cru.
     *
     * @return array{0: ?ClinicalSuggestionSource, 1: ?ClinicalProtocol}
     */
    private function suggestionOrigin(\Closure $practiceSupports, ?string $source, ?string $protocolUuid, int $medicineId, int $index): array
    {
        if ($source === ClinicalSuggestionSource::ClinicPractice->value) {
            // La pratique n'a pu proposer qu'un médicament réellement et
            // régulièrement prescrit pour l'un des diagnostics posés ici.
            if (! $practiceSupports($medicineId)) {
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
