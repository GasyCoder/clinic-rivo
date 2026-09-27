<?php

namespace App\Services\Medicine;

use App\Enums\PrescriptionStatus;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\DiagnosisCancellation;
use App\Models\PrescriptionLine;
use Illuminate\Support\Facades\Cache;

/**
 * Ce que la pratique de la clinique enseigne (ADR-111).
 *
 * Un algorithme **local** : il ne lit que la base du site, n'appelle aucun
 * service externe, et n'envoie rien hors de la clinique. Il apprend des
 * consultations déjà conclues par les médecins :
 *
 *   - quels mots de l'interrogatoire et de l'examen accompagnent quel
 *     diagnostic ;
 *   - quels médicaments, à quelle posologie, ont été prescrits pour quel
 *     diagnostic, et à quels âges.
 *
 * Il ne décide rien et n'invente rien : il rejoue ce que les médecins de la
 * clinique ont eux-mêmes fait, en disant sur combien de cas il s'appuie. Il
 * ne parle qu'à partir d'un nombre minimal de cas — une consultation isolée
 * est une anecdote, pas une pratique.
 */
class ClinicPracticeIndex
{
    /** En deçà, un diagnostic n'a pas assez d'histoire pour être proposé. */
    public const MIN_CASES = 3;

    /** Un mot, un médicament, doit revenir au moins deux fois. */
    public const MIN_OCCURRENCES = 2;

    /** Part minimale des cas d'un diagnostic où un médicament a été prescrit. */
    public const MIN_SHARE = 0.3;

    /**
     * Mots trop communs pour distinguer un diagnostic d'un autre. Les mots
     * de moins de quatre lettres sont déjà écartés par le découpage.
     */
    private const STOPWORDS = [
        'avec', 'sans', 'pour', 'dans', 'depuis', 'chez', 'mais', 'plus', 'moins', 'tres',
        'patient', 'patiente', 'jours', 'jour', 'semaine', 'semaines', 'mois', 'heures',
        'fois', 'aussi', 'etait', 'avoir', 'etre', 'fait', 'plusieurs', 'quelques',
        'notion', 'signale', 'presente', 'decrit', 'rapporte', 'depuis', 'apres', 'avant',
        'bien', 'bonne', 'bon', 'normal', 'normale', 'normaux', 'examen', 'consultation',
        'cette', 'celle', 'leur', 'elle', 'nous', 'vous', 'lors', 'sous', 'vers', 'entre',
    ];

    /**
     * L'index de la pratique, reconstruit dès qu'un fait nouveau existe.
     *
     * La clé suit l'état réel des données — dernier diagnostic, dernière
     * ligne d'ordonnance, nombre d'annulations — si bien qu'un diagnostic
     * retenu ou annulé est pris en compte à l'affichage suivant, sans
     * attendre l'expiration d'un cache.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $key = implode(':', [
            'clinic-practice-index', 'v1',
            (int) Diagnosis::query()->max('id'),
            DiagnosisCancellation::query()->count(),
            (int) PrescriptionLine::query()->max('id'),
            PrescriptionLine::query()->count(),
            (int) Consultation::query()->onlyTrashed()->count(),
        ]);

        return Cache::remember($key, now()->addHour(), fn (): array => $this->build());
    }

    /**
     * Découpe un texte en mots signifiants : quatre lettres au moins, ni
     * nombre ni mot vide. La clé est sans accents ni casse, pour que
     * « Fièvre » et « fievre » soient le même mot ; la valeur garde la forme
     * écrite, pour l'afficher telle que le médecin la lit. Un même mot ne
     * compte qu'une fois par consultation — le répéter ne le rend pas plus
     * vrai.
     *
     * @return array<string, string>
     */
    public static function terms(string $text): array
    {
        $terms = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', strip_tags($text)) ?: [] as $word) {
            $key = ClinicalProtocolMatcher::normalize($word);

            if (mb_strlen($key) < 4 || ctype_digit($key) || in_array($key, self::STOPWORDS, true) || isset($terms[$key])) {
                continue;
            }

            $terms[$key] = mb_strtolower($word);
        }

        return $terms;
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $diagnoses = [];
        $documentFrequency = [];
        $labels = [];
        $totalCases = 0;

        Consultation::query()
            ->whereHas('diagnoses', fn ($query) => $query
                ->whereNotNull('diagnostic_catalog_id')
                ->whereDoesntHave('cancellation'))
            ->with([
                'episode:id,patient_id,started_at',
                'episode.patient:id,birth_date,declared_age',
                'episode.serviceRequests:id,episode_id,designation',
                'clinicalExamination.findings',
                'diagnoses' => fn ($query) => $query
                    ->whereNotNull('diagnostic_catalog_id')
                    ->whereDoesntHave('cancellation')
                    ->with('diagnosticCatalog:id,uuid,code,name,is_active'),
                'prescriptions' => fn ($query) => $query
                    ->where('status', PrescriptionStatus::Active->value)
                    ->with(['lines' => fn ($lines) => $lines->whereNotNull('medicine_id')]),
            ])
            ->latest('id')
            // Borné : la pratique récente suffit à dire ce que la clinique
            // fait, et le calcul reste instantané.
            ->limit(3000)
            ->get()
            ->each(function (Consultation $consultation) use (&$diagnoses, &$documentFrequency, &$labels, &$totalCases): void {
                $catalogs = $consultation->diagnoses
                    ->pluck('diagnosticCatalog')
                    ->filter(fn ($catalog) => $catalog?->is_active)
                    ->unique('id');

                if ($catalogs->isEmpty()) {
                    return;
                }

                $totalCases++;
                $terms = self::terms($this->narrative($consultation));
                $age = $this->ageOf($consultation);

                foreach ($terms as $term => $label) {
                    $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
                    $labels[$term] ??= $label;
                }

                $lines = $consultation->prescriptions->flatMap->lines;

                foreach ($catalogs as $catalog) {
                    $entry = $diagnoses[$catalog->id] ?? [
                        'uuid' => $catalog->uuid,
                        'code' => $catalog->code,
                        'name' => $catalog->name,
                        'cases' => 0,
                        'terms' => [],
                        'consultation_ids' => [],
                        'prescribed_cases' => 0,
                        'medicines' => [],
                    ];

                    $entry['cases']++;
                    $entry['consultation_ids'][] = $consultation->id;

                    foreach (array_keys($terms) as $term) {
                        $entry['terms'][$term] = ($entry['terms'][$term] ?? 0) + 1;
                    }

                    if ($lines->isNotEmpty()) {
                        $entry['prescribed_cases']++;

                        foreach ($lines->unique('medicine_id') as $line) {
                            $medicine = $entry['medicines'][$line->medicine_id] ?? [
                                'count' => 0, 'posologies' => [], 'min_age' => null, 'max_age' => null,
                            ];
                            $medicine['count']++;
                            $posology = [
                                'dosage' => $line->dosage,
                                'route' => $line->route?->value,
                                'frequency' => $line->frequency,
                                'duration' => $line->duration,
                            ];
                            $posologyKey = implode('|', array_map(fn ($value) => (string) $value, $posology));
                            $medicine['posologies'][$posologyKey] ??= ['value' => $posology, 'count' => 0];
                            $medicine['posologies'][$posologyKey]['count']++;

                            if ($age !== null) {
                                $medicine['min_age'] = $medicine['min_age'] === null ? $age : min($medicine['min_age'], $age);
                                $medicine['max_age'] = $medicine['max_age'] === null ? $age : max($medicine['max_age'], $age);
                            }

                            $entry['medicines'][$line->medicine_id] = $medicine;
                        }
                    }

                    $diagnoses[$catalog->id] = $entry;
                }
            });

        return [
            'total_cases' => $totalCases,
            'document_frequency' => $documentFrequency,
            'labels' => $labels,
            'diagnoses' => $diagnoses,
        ];
    }

    /** Le récit clinique, tel que les deux moteurs le lisent. */
    public function narrative(Consultation $consultation): string
    {
        return ClinicalNarrative::of($consultation);
    }

    private function ageOf(Consultation $consultation): ?int
    {
        $patient = $consultation->episode?->patient;

        if (! $patient) {
            return null;
        }

        if ($patient->birth_date) {
            $reference = $consultation->episode->started_at ?? $consultation->created_at ?? now();

            return $patient->birth_date->isAfter($reference) ? null : (int) $patient->birth_date->diffInYears($reference);
        }

        return $patient->declared_age;
    }
}
