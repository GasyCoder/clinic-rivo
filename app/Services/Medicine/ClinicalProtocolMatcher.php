<?php

namespace App\Services\Medicine;

use App\Models\ClinicalProtocol;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\HospitalStay;
use App\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Propose un diagnostic et une ordonnance à partir des protocoles de la
 * clinique (ADR-111).
 *
 * Le moteur ne décide rien et n'invente rien. Il lit ce qui est déjà consigné
 * — interrogatoire, examen, âge, sexe, poids, allergies, diagnostics posés —
 * et le confronte aux protocoles rédigés par les médecins. Chaque proposition
 * dit **pourquoi** elle est faite (les signes retrouvés) et chaque exclusion
 * dit pourquoi elle l'est (« réservé à 1–14 ans ») : une proposition qu'on ne
 * peut pas expliquer ne peut pas être vérifiée par le médecin.
 */
class ClinicalProtocolMatcher
{
    /**
     * Ce que le dossier permet de savoir du patient, lu une seule fois.
     *
     * @return array{age: ?int, sex: ?string, weight: ?float, corpus: string, allergies: array<int, string>, diagnosis_catalog_ids: array<int, int>}
     */
    public function context(Consultation $consultation): array
    {
        $consultation->loadMissing([
            'episode.patient.allergies',
            'episode.careRecord',
            'clinicalExamination.findings',
            'diagnoses.cancellation',
        ]);

        $episode = $consultation->episode;
        $patient = $episode?->patient;

        $allergies = collect($patient?->allergies ?? [])
            ->pluck('substance')
            ->merge(collect($consultation->reported_allergies ?? [])->pluck('substance'))
            ->filter()
            ->map(fn ($substance) => (string) $substance)
            ->unique()
            ->values()
            ->all();

        $activeDiagnoses = $consultation->diagnoses
            ->filter(fn (Diagnosis $diagnosis) => $diagnosis->cancellation === null && $diagnosis->diagnostic_catalog_id !== null);

        return [
            'age' => $this->ageOf($consultation),
            'sex' => $patient?->sex?->value,
            'weight' => $episode?->careRecord?->weight_kg !== null ? (float) $episode->careRecord->weight_kg : null,
            'corpus' => self::normalize(ClinicalNarrative::of($consultation)),
            'allergies' => $allergies,
            'diagnosis_catalog_ids' => $activeDiagnoses->pluck('diagnostic_catalog_id')->unique()->values()->all(),
        ];
    }

    /**
     * ADR-163 — ce que le séjour permet de savoir du patient, pour lui proposer
     * une ordonnance comme en consultation.
     *
     * Les diagnostics sont ceux du passage — posés en consultation ou conclus
     * sur le séjour (ADR-147) — : ce sont eux que l'ordonnance traite. Aucun
     * texte n'est analysé : sur le séjour, on ne propose pas de diagnostic,
     * seulement l'ordonnance des diagnostics déjà posés.
     *
     * @return array{age: ?int, sex: ?string, weight: ?float, corpus: string, allergies: array<int, string>, diagnosis_catalog_ids: array<int, int>}
     */
    public function stayContext(HospitalStay $stay): array
    {
        $stay->loadMissing(['episode.patient.allergies', 'episode.careRecord']);

        $episode = $stay->episode;
        $patient = $episode?->patient;

        $fromConsultations = Diagnosis::query()
            ->whereHas('consultation', fn ($query) => $query->where('episode_id', $stay->episode_id))
            ->whereDoesntHave('cancellation')
            ->whereNotNull('diagnostic_catalog_id')
            ->pluck('diagnostic_catalog_id');

        $fromStay = $stay->diagnoses()->whereNotNull('diagnostic_catalog_id')->pluck('diagnostic_catalog_id');

        return [
            'age' => $this->ageAt($patient, $episode?->started_at),
            'sex' => $patient?->sex?->value,
            'weight' => $episode?->careRecord?->weight_kg !== null ? (float) $episode->careRecord->weight_kg : null,
            'corpus' => '',
            'allergies' => collect($patient?->allergies ?? [])
                ->pluck('substance')
                ->filter()
                ->map(fn ($substance) => (string) $substance)
                ->unique()
                ->values()
                ->all(),
            'diagnosis_catalog_ids' => $fromConsultations->concat($fromStay)->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];
    }

    /**
     * Diagnostics dont les signes évocateurs se retrouvent dans le dossier.
     *
     * Les protocoles d'un même diagnostic (adulte / enfant…) sont réunis :
     * c'est le diagnostic qu'on propose, pas le protocole. Un diagnostic déjà
     * posé n'est pas reproposé.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestDiagnoses(Consultation $consultation, ?array $context = null): array
    {
        $context ??= $this->context($consultation);

        if ($context['corpus'] === '') {
            return [];
        }

        return $this->activeProtocols()
            ->reject(fn (ClinicalProtocol $protocol) => in_array($protocol->diagnostic_catalog_id, $context['diagnosis_catalog_ids'], true))
            ->filter(fn (ClinicalProtocol $protocol) => $protocol->diagnosticCatalog?->is_active)
            // Âge et sexe seulement : le poids choisit une posologie, il ne
            // rend pas un diagnostic plus ou moins probable.
            ->filter(fn (ClinicalProtocol $protocol) => $this->populationMismatch($protocol, $context, withWeight: false) === null)
            ->groupBy('diagnostic_catalog_id')
            ->map(function (Collection $protocols) use ($context): ?array {
                $indications = $protocols
                    ->flatMap(fn (ClinicalProtocol $protocol) => $protocol->indications ?? [])
                    ->map(fn ($sign) => trim((string) $sign))
                    ->filter()
                    ->unique(fn ($sign) => self::normalize($sign))
                    ->values();

                $matched = $indications
                    ->filter(fn ($sign) => $this->mentions($context['corpus'], $sign))
                    ->values();

                if ($matched->isEmpty()) {
                    return null;
                }

                $catalog = $protocols->first()->diagnosticCatalog;

                return [
                    'source' => 'PROTOCOL',
                    'diagnostic_catalog_uuid' => $catalog->uuid,
                    'code' => $catalog->code,
                    'name' => $catalog->name,
                    'protocol_uuid' => $protocols->first()->uuid,
                    'matched_signs' => $matched->all(),
                    'total_signs' => $indications->count(),
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $suggestion) => [
                count($suggestion['matched_signs']),
                count($suggestion['matched_signs']) / max(1, $suggestion['total_signs']),
            ])
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * L'ordonnance type des diagnostics déjà posés, pour ce patient-là.
     *
     * Un protocole qui ne convient pas n'est pas tu : il est rendu dans
     * `excluded` avec sa raison, sinon le médecin conclurait qu'aucun
     * protocole n'existe pour ce diagnostic.
     *
     * @return array{protocols: array<int, array<string, mixed>>, excluded: array<int, array<string, string>>}
     */
    public function suggestPrescription(Consultation $consultation, ?array $context = null): array
    {
        return $this->prescriptionFor($context ?? $this->context($consultation));
    }

    /**
     * La même ordonnance type, à partir d'un contexte déjà lu : c'est ainsi que
     * le séjour la reçoit (ADR-163), sans consultation à ouvrir.
     *
     * @param  array{age: ?int, sex: ?string, weight: ?float, allergies: array<int, string>, diagnosis_catalog_ids: array<int, int>}  $context
     * @return array{protocols: array<int, array<string, mixed>>, excluded: array<int, array<string, string>>}
     */
    public function prescriptionFor(array $context): array
    {
        if ($context['diagnosis_catalog_ids'] === []) {
            return ['protocols' => [], 'excluded' => []];
        }

        $protocols = $this->activeProtocols()
            ->filter(fn (ClinicalProtocol $protocol) => in_array($protocol->diagnostic_catalog_id, $context['diagnosis_catalog_ids'], true));

        $applicable = [];
        $excluded = [];

        foreach ($protocols as $protocol) {
            $reason = $this->populationMismatch($protocol, $context, withWeight: true);

            if ($reason !== null) {
                $excluded[] = ['protocol_uuid' => $protocol->uuid, 'name' => $protocol->name, 'reason' => $reason];

                continue;
            }

            $applicable[] = [
                'source' => 'PROTOCOL',
                'key' => $protocol->uuid,
                // Interne : le presenter s'en sert pour savoir quels
                // diagnostics un protocole couvre déjà, puis le retire —
                // aucun identifiant SQL ne quitte le serveur (ADR-005).
                'diagnostic_catalog_id' => $protocol->diagnostic_catalog_id,
                'protocol_uuid' => $protocol->uuid,
                'name' => $protocol->name,
                'diagnosis' => $protocol->diagnosticCatalog?->name,
                'notes' => $protocol->notes,
                'lines' => $protocol->lines
                    ->filter(fn ($line) => $line->medicine?->active && $line->medicine->catalogItem !== null)
                    ->map(fn ($line) => [
                        'medicine_uuid' => $line->medicine->catalogItem->uuid,
                        'medicine_name' => $line->medicine->catalogItem->name,
                        'dosage' => $line->dosage,
                        'route' => $line->route?->value,
                        'frequency' => $line->frequency,
                        'duration' => $line->duration,
                        'quantity' => $line->quantity,
                        'instructions' => $line->instructions,
                        'allergy_conflict' => $this->allergyConflict(
                            $context['allergies'],
                            [$line->medicine->generic_name, $line->medicine->catalogItem->name],
                        ),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return ['protocols' => $applicable, 'excluded' => $excluded];
    }

    /** Nombre de protocoles actifs : zéro change ce que l'écran doit dire. */
    public function activeProtocolCount(): int
    {
        return ClinicalProtocol::query()->where('is_active', true)->count();
    }

    /**
     * Pourquoi ce protocole ne convient pas à ce patient — ou `null`.
     *
     * Une borne posée exclut un patient dont la valeur est inconnue : un
     * protocole pédiatrique ne s'applique pas faute de connaître l'âge.
     */
    public function populationMismatch(ClinicalProtocol $protocol, array $context, bool $withWeight): ?string
    {
        $age = $context['age'];

        if ($protocol->min_age_years !== null || $protocol->max_age_years !== null) {
            $range = $this->rangeLabel($protocol->min_age_years, $protocol->max_age_years, 'ans');

            if ($age === null) {
                return "Réservé aux patients de {$range} — âge inconnu";
            }

            if (($protocol->min_age_years !== null && $age < $protocol->min_age_years)
                || ($protocol->max_age_years !== null && $age > $protocol->max_age_years)) {
                return "Réservé aux patients de {$range} — patient de {$age} ans";
            }
        }

        if ($protocol->sex !== null && $protocol->sex->value !== $context['sex']) {
            return $protocol->sex->value === 'F' ? 'Réservé aux patientes' : 'Réservé aux patients de sexe masculin';
        }

        if (! $withWeight || ($protocol->min_weight_kg === null && $protocol->max_weight_kg === null)) {
            return null;
        }

        $weight = $context['weight'];
        $range = $this->rangeLabel(
            $protocol->min_weight_kg !== null ? (float) $protocol->min_weight_kg : null,
            $protocol->max_weight_kg !== null ? (float) $protocol->max_weight_kg : null,
            'kg',
        );

        if ($weight === null) {
            return "Posologie prévue pour {$range} — poids non relevé aux Soins";
        }

        if (($protocol->min_weight_kg !== null && $weight < (float) $protocol->min_weight_kg)
            || ($protocol->max_weight_kg !== null && $weight > (float) $protocol->max_weight_kg)) {
            return "Posologie prévue pour {$range} — patient de ".self::number($weight).' kg';
        }

        return null;
    }

    /**
     * L'allergie connue que ce médicament recoupe, ou `null`.
     *
     * Comparaison par mots entiers, sans accents ni casse, dans les deux
     * sens : « Pénicilline » recoupe « pénicilline G », « Amoxicilline »
     * recoupe « Amoxicilline 500 mg ». Un signal, jamais une interdiction —
     * le médecin peut avoir ses raisons, mais il ne le fera pas sans le voir.
     *
     * @param  array<int, string>  $allergies
     * @param  array<int, ?string>  $names
     */
    public function allergyConflict(array $allergies, array $names): ?string
    {
        $haystack = self::normalize(implode(' ', array_filter($names)));

        foreach ($allergies as $allergy) {
            $needle = self::normalize($allergy);

            if ($needle !== '' && ($this->mentions($haystack, $allergy) || $this->mentions($needle, $haystack))) {
                return $allergy;
            }
        }

        return null;
    }

    /** Minuscules, sans accents, ponctuation réduite à des espaces. */
    public static function normalize(?string $text): string
    {
        return (string) Str::of(Str::ascii((string) $text))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    /** Recherche par mots entiers : « toux » ne se trouve pas dans « touxine ». */
    private function mentions(string $normalizedCorpus, string $needle): bool
    {
        $needle = self::normalize($needle);

        return $needle !== '' && str_contains(" {$normalizedCorpus} ", " {$needle} ");
    }

    /** @return Collection<int, ClinicalProtocol> */
    private function activeProtocols(): Collection
    {
        return ClinicalProtocol::query()
            ->where('is_active', true)
            ->with(['diagnosticCatalog', 'lines.medicine.catalogItem'])
            ->orderBy('name')
            ->get();
    }

    private function ageOf(Consultation $consultation): ?int
    {
        return $this->ageAt($consultation->episode?->patient, $consultation->episode?->started_at);
    }

    /** L'âge au passage : une ordonnance se règle sur l'âge qu'avait le patient ce jour-là. */
    private function ageAt(?Patient $patient, mixed $reference): ?int
    {
        if (! $patient) {
            return null;
        }

        if ($patient->birth_date) {
            $reference ??= now();

            return $patient->birth_date->isAfter($reference)
                ? null
                : (int) $patient->birth_date->diffInYears($reference);
        }

        return $patient->declared_age;
    }

    private function rangeLabel(int|float|null $min, int|float|null $max, string $unit): string
    {
        return match (true) {
            $min !== null && $max !== null => self::number($min).'–'.self::number($max)." {$unit}",
            $min !== null => self::number($min)." {$unit} et plus",
            default => 'jusqu’à '.self::number($max)." {$unit}",
        };
    }

    private static function number(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',');
    }
}
