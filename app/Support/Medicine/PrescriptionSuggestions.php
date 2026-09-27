<?php

namespace App\Support\Medicine;

use App\Models\Consultation;
use App\Services\Medicine\ClinicalProtocolMatcher;
use App\Services\Medicine\ClinicPracticeAdvisor;
use App\Services\Pharmacy\MedicineStockService;

/**
 * L'ordonnance proposée pour les diagnostics posés (ADR-111), écrite une seule fois.
 *
 * La consultation et le séjour (ADR-163) la reçoivent de la même façon :
 * protocoles d'abord — ils sont la décision écrite de la clinique —, puis la
 * pratique observée pour ce qu'aucun protocole ne couvre, et la disponibilité
 * en stock jointe par le même calcul que le catalogue. Un produit épuisé reste
 * visible — le protocole le prévoit — mais n'est jamais ajoutable.
 *
 * Calculée à chaque affichage, jamais enregistrée : une proposition n'est pas
 * un fait clinique.
 */
final class PrescriptionSuggestions
{
    public function __construct(
        private readonly ClinicalProtocolMatcher $protocols,
        private readonly ClinicPracticeAdvisor $practice,
        private readonly MedicineStockService $medicineStock,
    ) {}

    /**
     * @param  array{age: ?int, sex: ?string, weight: ?float, allergies: array<int, string>, diagnosis_catalog_ids: array<int, int>}  $context
     * @param  Consultation|null  $self  la consultation à retirer de sa propre preuve
     * @return array{groups: array<int, array<string, mixed>>, excluded: array<int, array<string, string>>}
     */
    public function for(array $context, ?Consultation $self = null): array
    {
        $protocolPrescription = $this->protocols->prescriptionFor($context);
        $covered = array_column($protocolPrescription['protocols'], 'diagnostic_catalog_id');

        $groups = [
            ...array_map(function (array $group): array {
                // Interne : aucun identifiant SQL ne quitte le serveur (ADR-005).
                unset($group['diagnostic_catalog_id']);

                return $group;
            }, $protocolPrescription['protocols']),
            ...$this->practice->prescriptionFor(
                $context,
                array_values(array_diff($context['diagnosis_catalog_ids'], $covered)),
                $self,
            ),
        ];

        if ($groups !== []) {
            $stock = $this->medicineStock->availableCatalog()->keyBy('uuid');

            $groups = array_map(fn (array $group): array => [
                ...$group,
                'lines' => array_map(fn (array $line): array => [
                    ...$line,
                    'available_quantity' => $stock->get($line['medicine_uuid'])['available_quantity'] ?? 0,
                    'available' => (bool) ($stock->get($line['medicine_uuid'])['available'] ?? false),
                    'unit' => $stock->get($line['medicine_uuid'])['unit'] ?? null,
                ], $group['lines']),
            ], $groups);
        }

        return ['groups' => $groups, 'excluded' => $protocolPrescription['excluded']];
    }
}
