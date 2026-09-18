<?php

namespace App\Services\Medicine;

use App\Enums\PrescriptionStatus;
use App\Models\Consultation;
use App\Models\Medicine;

/**
 * Propositions tirées de la pratique de la clinique (ADR-111).
 *
 * Complète les protocoles, ne les remplace jamais : un diagnostic qu'un
 * protocole propose déjà, ou dont l'ordonnance type s'applique, n'est pas
 * reproposé ici. Tout est calculé sur la base du site, sans aucun service
 * externe.
 *
 * Chaque proposition porte sa preuve — « dans 4 des 6 consultations pour ce
 * diagnostic » — pour que le médecin juge de sa solidité au lieu de la croire.
 */
class ClinicPracticeAdvisor
{
    public function __construct(
        private readonly ClinicPracticeIndex $index,
        private readonly ClinicalProtocolMatcher $matcher,
    ) {}

    /**
     * Diagnostics dont le vocabulaire se retrouve dans ce dossier.
     *
     * Un mot pèse d'autant plus qu'il est **fréquent** dans les consultations
     * de ce diagnostic et **rare** dans les autres : « toux » distingue une
     * bronchite, « douleur » ne distingue presque rien.
     *
     * @param  array<int, string>  $excludedCatalogUuids  déjà posés ou déjà proposés par un protocole
     * @return array<int, array<string, mixed>>
     */
    public function suggestDiagnoses(Consultation $consultation, array $context, array $excludedCatalogUuids): array
    {
        $index = $this->index->index();
        $terms = array_keys(ClinicPracticeIndex::terms($this->index->narrative($consultation)));

        if ($terms === [] || $index['total_cases'] === 0) {
            return [];
        }

        $suggestions = [];

        foreach ($index['diagnoses'] as $catalogId => $entry) {
            if (in_array($catalogId, $context['diagnosis_catalog_ids'], true)
                || in_array($entry['uuid'], $excludedCatalogUuids, true)
                || $entry['cases'] < ClinicPracticeIndex::MIN_CASES) {
                continue;
            }

            $matched = [];
            $score = 0.0;

            foreach ($terms as $term) {
                $count = $entry['terms'][$term] ?? 0;

                if ($count < ClinicPracticeIndex::MIN_OCCURRENCES) {
                    continue;
                }

                $rarity = log(1 + $index['total_cases'] / max(1, $index['document_frequency'][$term] ?? 1));
                $score += ($count / $entry['cases']) * $rarity;
                $matched[] = ['term' => $index['labels'][$term] ?? $term, 'count' => $count];
            }

            if ($matched === []) {
                continue;
            }

            usort($matched, fn (array $a, array $b) => $b['count'] <=> $a['count']);

            $suggestions[] = [
                'source' => 'CLINIC_PRACTICE',
                'diagnostic_catalog_uuid' => $entry['uuid'],
                'code' => $entry['code'],
                'name' => $entry['name'],
                'protocol_uuid' => null,
                'matched_terms' => $matched,
                'cases' => $entry['cases'],
                'score' => round($score, 4),
            ];
        }

        usort($suggestions, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($suggestions, 0, 3);
    }

    /**
     * Ce que les médecins de la clinique prescrivent pour ces diagnostics.
     *
     * La consultation en cours est retirée du calcul : elle ne peut pas se
     * servir de preuve à elle-même. La posologie proposée est celle qui
     * revient le plus souvent ; la quantité est laissée au calcul de la
     * posologie (ADR-110). Un patient hors de la tranche d'âge déjà traitée
     * est signalé — une dose d'adulte ne se transpose pas à un enfant.
     *
     * @param  array<int, int>  $catalogIds  diagnostics posés sans protocole applicable
     * @return array<int, array<string, mixed>>
     */
    public function suggestPrescription(Consultation $consultation, array $context, array $catalogIds): array
    {
        if ($catalogIds === []) {
            return [];
        }

        $index = $this->index->index();
        $own = $this->ownLines($consultation);
        $groups = [];

        foreach ($catalogIds as $catalogId) {
            $entry = $index['diagnoses'][$catalogId] ?? null;

            if ($entry === null) {
                continue;
            }

            $includesSelf = in_array($consultation->id, $entry['consultation_ids'], true);
            $cases = $entry['prescribed_cases'] - ($includesSelf && $own !== [] ? 1 : 0);

            if ($cases < ClinicPracticeIndex::MIN_CASES) {
                continue;
            }

            $candidates = [];

            foreach ($entry['medicines'] as $medicineId => $stats) {
                $count = $stats['count'] - ($includesSelf && isset($own[$medicineId]) ? 1 : 0);

                if ($count < ClinicPracticeIndex::MIN_OCCURRENCES || $count / $cases < ClinicPracticeIndex::MIN_SHARE) {
                    continue;
                }

                $posologies = $stats['posologies'];

                if ($includesSelf && isset($own[$medicineId], $posologies[$own[$medicineId]])) {
                    $posologies[$own[$medicineId]]['count']--;
                }

                $candidates[$medicineId] = [
                    'count' => $count,
                    'posology' => collect($posologies)->where('count', '>', 0)->sortByDesc('count')->first()['value'] ?? [],
                    'min_age' => $stats['min_age'],
                    'max_age' => $stats['max_age'],
                ];
            }

            if ($candidates === []) {
                continue;
            }

            uasort($candidates, fn (array $a, array $b) => $b['count'] <=> $a['count']);
            $medicines = Medicine::query()
                ->whereIn('id', array_keys($candidates))
                ->where('active', true)
                ->with('catalogItem:id,uuid,name')
                ->get()
                ->keyBy('id');

            $lines = [];

            foreach (array_slice($candidates, 0, 6, true) as $medicineId => $candidate) {
                $medicine = $medicines->get($medicineId);

                if (! $medicine?->catalogItem) {
                    continue;
                }

                $ageNote = $this->ageNote($context['age'], $candidate['min_age'], $candidate['max_age']);

                $lines[] = [
                    'medicine_uuid' => $medicine->catalogItem->uuid,
                    'medicine_name' => $medicine->catalogItem->name,
                    'dosage' => $candidate['posology']['dosage'] ?? null,
                    'route' => $candidate['posology']['route'] ?? null,
                    'frequency' => $candidate['posology']['frequency'] ?? null,
                    'duration' => $candidate['posology']['duration'] ?? null,
                    'quantity' => null,
                    'instructions' => null,
                    'support' => ['count' => $candidate['count'], 'cases' => $cases],
                    'age_note' => $ageNote,
                    'allergy_conflict' => $this->matcher->allergyConflict(
                        $context['allergies'],
                        [$medicine->generic_name, $medicine->catalogItem->name],
                    ),
                ];
            }

            if ($lines !== []) {
                $groups[] = [
                    'source' => 'CLINIC_PRACTICE',
                    'key' => "practice-{$entry['uuid']}",
                    'protocol_uuid' => null,
                    'name' => 'Pratique de la clinique',
                    'diagnosis' => $entry['name'],
                    'notes' => "Ce que les médecins de la clinique ont prescrit dans {$cases} consultations pour ce diagnostic.",
                    'lines' => $lines,
                ];
            }
        }

        return $groups;
    }

    /** Combien de consultations conclues la pratique connaît déjà. */
    public function caseCount(): int
    {
        return $this->index->index()['total_cases'];
    }

    /** Le diagnostic a-t-il assez d'histoire pour avoir été proposé ? */
    public function supportsDiagnosis(int $catalogId): bool
    {
        return ($this->index->index()['diagnoses'][$catalogId]['cases'] ?? 0) >= ClinicPracticeIndex::MIN_CASES;
    }

    /**
     * Ce médicament a-t-il été réellement prescrit, assez souvent, pour l'un
     * des diagnostics posés dans cette consultation ?
     */
    public function supportsMedicine(Consultation $consultation, int $medicineId): bool
    {
        $catalogIds = $consultation->diagnoses()
            ->whereNotNull('diagnostic_catalog_id')
            ->whereDoesntHave('cancellation')
            ->pluck('diagnostic_catalog_id');
        $index = $this->index->index();

        foreach ($catalogIds as $catalogId) {
            if (($index['diagnoses'][$catalogId]['medicines'][$medicineId]['count'] ?? 0) >= ClinicPracticeIndex::MIN_OCCURRENCES) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les médicaments que cette consultation a déjà prescrits, avec la clé de
     * leur posologie, pour les retirer de sa propre preuve.
     *
     * @return array<int, string>
     */
    private function ownLines(Consultation $consultation): array
    {
        return $consultation->prescriptions()
            ->where('status', PrescriptionStatus::Active->value)
            ->with(['lines' => fn ($query) => $query->whereNotNull('medicine_id')])
            ->get()
            ->flatMap->lines
            ->unique('medicine_id')
            ->mapWithKeys(fn ($line) => [$line->medicine_id => implode('|', [
                (string) $line->dosage, (string) $line->route?->value, (string) $line->frequency, (string) $line->duration,
            ])])
            ->all();
    }

    private function ageNote(?int $age, ?int $min, ?int $max): ?string
    {
        if ($min === null || $max === null) {
            return null;
        }

        $range = $min === $max ? "{$min} ans" : "{$min} à {$max} ans";

        if ($age === null) {
            return "Prescrit jusqu’ici à des patients de {$range} — âge de ce patient inconnu";
        }

        if ($age < $min || $age > $max) {
            return "Prescrit jusqu’ici à des patients de {$range} — ce patient a {$age} ans";
        }

        return null;
    }
}
