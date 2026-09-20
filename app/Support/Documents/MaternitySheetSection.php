<?php

namespace App\Support\Documents;

use App\Enums\CatalogModule;
use App\Models\Episode;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\PatientNewbornLink;
use App\Models\User;
use App\Support\NewbornFiche;

/**
 * ADR-143 — la section « Maternité » du dossier médical.
 *
 * Le dossier médical d'un passage est UN document : identité, constantes,
 * allergies, antécédents… et, quand la patiente a été prise en charge en
 * Maternité, ce que la Maternité a consigné. Rien n'est recopié ni dupliqué :
 * la section relit `maternity_records` et ses actes, exactement comme la
 * feuille relit la fiche Soins ou le séjour hospitalier (ADR-116).
 *
 * Les bébés en font partie : chaque nouveau-né a son bloc (sexe, poids,
 * Apgar, état, soins). Tant qu'un bébé n'est pas un patient à part entière,
 * c'est ici, dans le dossier de sa mère, que son suivi se lit.
 *
 * Une case que personne n'a remplie reste `null` — jamais « Non », jamais
 * « Normal » (ADR-077). Gardée par `maternity.view`, comme l'écran Maternité
 * qui sert les mêmes données : une feuille imprimée n'est pas un moyen de
 * contourner ce droit (ADR-054).
 */
final class MaternitySheetSection
{
    private const MEMBRANES = ['INTACT' => 'Intactes', 'RUPTURED' => 'Rompues'];

    private const DELIVERY_MODES = [
        'VAGINAL' => 'Voie basse',
        'INSTRUMENTAL' => 'Instrumental',
        'CESAREAN' => 'Césarienne',
    ];

    private const NEWBORN_SEX = ['F' => 'Féminin', 'M' => 'Masculin', 'UNDETERMINED' => 'Indéterminé'];

    /**
     * `null` : ce passage n'a aucun dossier Maternité — la section n'existe pas.
     *
     * @return array<string, mixed>|null
     */
    public function present(Episode $episode, User $user): ?array
    {
        $record = $episode->relationLoaded('maternityRecord')
            ? $episode->maternityRecord
            : $episode->maternityRecord()->first();

        if ($record === null) {
            return null;
        }

        // Le droit manque : la section se nomme comme restreinte, elle n'est pas
        // servie vide — un vide se lirait « rien n'a été consigné ».
        if (! $user->can('maternity.view')) {
            return ['restricted' => true];
        }

        $record->loadMissing('procedures');

        return [
            'restricted' => false,
            'context' => $this->text($record->obstetric_context),
            'pregnancy' => [
                'gravidity' => $this->value($record->pregnancy_data['gravidity'] ?? null),
                'parity' => $this->value($record->pregnancy_data['parity'] ?? null),
                'last_menstrual_period' => $this->text($record->pregnancy_data['last_menstrual_period'] ?? null),
                'estimated_due_date' => $this->text($record->pregnancy_data['estimated_due_date'] ?? null),
                'risk_factors' => $this->text($record->pregnancy_data['risk_factors'] ?? null),
            ],
            'prenatal' => [
                'gestational_age_weeks' => $this->value($record->prenatal_data['gestational_age_weeks'] ?? null),
                'fundal_height_cm' => $this->value($record->prenatal_data['fundal_height_cm'] ?? null),
                'fetal_heart_rate' => $this->value($record->prenatal_data['fetal_heart_rate'] ?? null),
                'notes' => $this->text($record->prenatal_data['notes'] ?? null),
            ],
            'labor' => [
                'started_at' => $this->text($record->labor_data['started_at'] ?? null),
                'membranes' => self::MEMBRANES[$record->labor_data['membranes_status'] ?? ''] ?? null,
                'cervical_dilation_cm' => $this->value($record->labor_data['cervical_dilation_cm'] ?? null),
                'contractions' => $this->text($record->labor_data['contractions'] ?? null),
                'surveillance_notes' => $this->text($record->labor_data['surveillance_notes'] ?? null),
            ],
            'delivery' => [
                'occurred_at' => $this->text($record->delivery_data['occurred_at'] ?? null),
                'mode' => self::DELIVERY_MODES[$record->delivery_data['mode'] ?? ''] ?? null,
                'placenta_status' => $this->text($record->delivery_data['placenta_status'] ?? null),
                'complications' => $this->text($record->delivery_data['complications'] ?? null),
            ],
            'newborns' => $this->newborns($record, $episode->patient?->last_name),
            'baby_care_notes_legacy' => $this->text($record->baby_care_notes),
            'maternal_care_notes' => $this->text($record->maternal_care_notes),
            'procedures' => $record->procedures
                ->map(fn (MaternityProcedure $procedure) => [
                    'name' => $procedure->procedure_name,
                    'quantity' => rtrim(rtrim(number_format((float) $procedure->quantity, 2, '.', ''), '0'), '.'),
                    'notes' => $this->text($procedure->notes),
                    'performed_at' => $procedure->performed_at?->toDateTimeString(),
                ])
                ->values()
                ->all(),
            'observations' => $this->text($record->observations),
            'transmission_notes' => $this->text($record->transmission_notes),
        ];
    }

    /**
     * Un bloc par bébé, dans l'ordre saisi. Un bébé dont aucune case n'est
     * remplie n'est pas listé : une fiche vide ouverte d'office pour des
     * jumeaux (ADR-136) n'est pas un nouveau-né consigné.
     *
     * @return list<array<string, mixed>>
     */
    private function newborns(MaternityRecord $record, ?string $motherLastName = null): array
    {
        // Le numéro du dossier patient du bébé (ADR-144), quand il existe.
        $patientNumbers = PatientNewbornLink::query()
            ->where('maternity_record_id', $record->getKey())
            ->with('patient:id,patient_number')
            ->get()
            ->mapWithKeys(fn (PatientNewbornLink $link) => [$link->newborn_uuid => $link->patient?->patient_number]);

        return collect($record->newborn_data['newborns'] ?? [])
            ->map(fn (array $newborn, int $index) => [
                'rank' => $index + 1,
                'name' => NewbornFiche::displayName($newborn, $motherLastName, $index + 1),
                'patient_number' => $patientNumbers[$newborn['uuid'] ?? ''] ?? null,
                'sex' => self::NEWBORN_SEX[$newborn['sex'] ?? ''] ?? null,
                'birth_weight_g' => $this->value($newborn['birth_weight_g'] ?? null),
                'apgar' => $this->value($newborn['apgar'] ?? null),
                'condition' => $this->text($newborn['condition'] ?? null),
                'care_notes' => $this->text($newborn['care_notes'] ?? null),
            ])
            ->filter(fn (array $newborn) => collect($newborn)->except(['rank', 'name', 'patient_number'])->contains(fn ($value) => $value !== null))
            ->values()
            ->all();
    }

    /** Un nombre saisi à 0 est une valeur (Apgar 0) : seul le vide est une absence. */
    private function value(mixed $value): int|float|string|null
    {
        return $value === null || $value === '' ? null : $value;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * La naissance d'un patient qui est un nouveau-né (ADR-144, ADR-145) : ce que la Maternité a consigné
     * **de lui et de sa naissance**, lu depuis le dossier de sa mère.
     *
     * @return array<string, mixed>|null `null` : ce patient n'est pas un nouveau-né de la clinique
     */
    public function birth(Patient $patient, User $user): ?array
    {
        $link = PatientNewbornLink::query()
            ->where('patient_id', $patient->getKey())
            ->with(['mother.addressEntry:id,label', 'maternityRecord'])
            ->first();

        if ($link === null) {
            return null;
        }

        return $this->birthOf($link->maternityRecord, $link->newborn_uuid, $link->birth_rank, $link->mother, $user);
    }

    /**
     * La naissance d'un bébé désigné par sa fiche (ADR-146) : que le bébé soit déjà patient ou non, c'est la
     * même lecture — la fiche du dossier de sa mère.
     *
     * Le dossier d'un nouveau-né n'est pas celui d'un adulte : il lit ses circonstances de naissance — date et
     * heure, mode d'accouchement, terme, naissance unique ou multiple, complications de l'accouchement — puis
     * son état (poids, Apgar, état, soins), et le contact de sa mère, seule personne à joindre. Ce qui reste
     * **à la mère** ne passe pas : antécédents obstétricaux (gestité, parité), facteurs de risque, surveillance
     * du travail, délivrance, soins maternels.
     *
     * @return array<string, mixed>
     */
    public function birthOf(?MaternityRecord $record, string $newbornUuid, int $rank, ?Patient $mother, User $user): array
    {
        // La mère est la personne à joindre : le bébé n'a ni téléphone ni adresse à lui.
        $motherBlock = [
            'patient_number' => $mother?->patient_number,
            'name' => trim(($mother?->last_name ?? '').' '.($mother?->first_name ?? '')),
            'phone' => $mother?->phone,
            'address' => $mother ? (PaperPatient::present($mother)['address'] ?? null) : null,
        ];

        // Une naissance à la clinique se passe dans ce site : le lieu n'est pas ressaisi.
        $place = config('rivo.site.name');

        // ADR-146 (amendement) — le droit du dossier du bébé, jamais celui du dossier obstétrical de sa
        // mère : sa naissance appartient à son propre dossier, et `maternity.view` le fermait à Médecine.
        if (! $user->can('newborns.medical_record.view')) {
            return ['restricted' => true, 'rank' => $rank, 'mother' => $motherBlock, 'place' => $place];
        }

        $entries = collect($record?->newborn_data['newborns'] ?? []);
        $newborn = $entries->first(fn (array $entry) => ($entry['uuid'] ?? null) === $newbornUuid) ?? [];

        return [
            'restricted' => false,
            'rank' => $rank,
            // Combien de bébés dans ce dossier : « naissance unique » ou « nº 1 sur 2 ».
            'births_count' => max($entries->count(), 1),
            'mother' => $motherBlock,
            'place' => $place,
            'born_at' => $this->text($record?->delivery_data['occurred_at'] ?? null),
            'delivery_mode' => self::DELIVERY_MODES[$record?->delivery_data['mode'] ?? ''] ?? null,
            'gestational_age_weeks' => $this->value($record?->prenatal_data['gestational_age_weeks'] ?? null),
            'delivery_complications' => $this->text($record?->delivery_data['complications'] ?? null),
            'sex' => self::NEWBORN_SEX[$newborn['sex'] ?? ''] ?? null,
            'birth_weight_g' => $this->value($newborn['birth_weight_g'] ?? null),
            'apgar' => $this->value($newborn['apgar'] ?? null),
            'condition' => $this->text($newborn['condition'] ?? null),
            'care_notes' => $this->text($newborn['care_notes'] ?? null),
        ];
    }

    /**
     * Les bébés d'un passage : ce que le composant « Nouveau-nés » affiche partout (ADR-145, ADR-146).
     *
     * Une seule projection pour le détail du passage et le dossier Maternité. Chaque bébé y a son nom, son état
     * — patient ou pas encore — et son dossier médical, qui s'ouvre **dès la fiche**, sans attendre qu'il soit
     * patient. Une fiche jamais remplie est nommée telle quelle : elle ne se lit pas « pas de bébé ».
     *
     * Deux droits, parce que ce bloc dit deux choses (ADR-146 amendement) : `newborns.view` l'ouvre — un
     * bébé, son rang, son nom, son état —, et seul `newborns.medical_record.view` y montre le poids de
     * naissance, qui est clinique.
     *
     * @return array<string, mixed>|null `null` : aucun dossier Maternité, ou pas le droit `newborns.view`
     */
    public function forPassage(Episode $episode, User $user): ?array
    {
        $record = $episode->relationLoaded('maternityRecord')
            ? $episode->maternityRecord
            : $episode->maternityRecord()->first();

        if ($record === null || ! $user->can('newborns.view')) {
            return null;
        }

        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Maternity->value)
            ->latest('id')
            ->first();

        $links = PatientNewbornLink::query()
            ->where('maternity_record_id', $record->getKey())
            ->with('patient:id,uuid,patient_number')
            ->get()
            ->keyBy('newborn_uuid');

        $canOpenPatients = $user->can('patients.view');
        $canReadRecord = $user->can('newborns.medical_record.view');
        $motherLast = $episode->patient?->last_name;

        return [
            'maternity_url' => $orientation ? "/maternity/orientations/{$orientation->uuid}" : null,
            'newborns' => collect($record->newborn_data['newborns'] ?? [])
                ->map(function (array $newborn, int $index) use ($links, $canOpenPatients, $canReadRecord, $episode, $motherLast): array {
                    $uuid = $newborn['uuid'] ?? null;
                    // `get()` et non `[]` : un bébé sans identité (aucun dossier patient) n'est pas une erreur.
                    $patient = $links->get($uuid ?? '')?->patient;
                    $filled = NewbornFiche::isFilled($newborn);

                    return [
                        'rank' => $index + 1,
                        'index' => $index,
                        'uuid' => $uuid,
                        'filled' => $filled,
                        'name' => NewbornFiche::displayName($newborn, $motherLast, $index + 1),
                        'sex' => self::NEWBORN_SEX[$newborn['sex'] ?? ''] ?? null,
                        // Le poids est clinique : il n'accompagne l'identité qu'avec le droit du dossier.
                        'birth_weight_g' => $canReadRecord ? $this->value($newborn['birth_weight_g'] ?? null) : null,
                        'patient_number' => $patient?->patient_number,
                        // Les liens ne sont servis qu'à qui peut ouvrir un dossier.
                        'patient_url' => $patient && $canOpenPatients ? "/patients/{$patient->uuid}" : null,
                        // Le dossier du bébé s'ouvre dès sa fiche : celui du patient quand il l'est, sinon
                        // celui que la fiche seule permet de lire — et seulement avec le droit de le lire.
                        'medical_record_url' => ! $canOpenPatients || ! $uuid || ! $filled
                            ? null
                            : ($patient
                                ? "/patients/{$patient->uuid}/dossier-medical"
                                : ($canReadRecord ? $this->newbornRecordUrl($episode, $uuid) : null)),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /** Le dossier médical d'un bébé qui n'est pas encore patient : lu depuis sa fiche, chez sa mère (ADR-146). */
    public function newbornRecordUrl(Episode $motherEpisode, string $newbornUuid): string
    {
        return "/passages/{$motherEpisode->uuid}/nouveau-nes/{$newbornUuid}/dossier-medical";
    }

    /**
     * La mère et ses bébés, réunis en onglets sur le même modèle de dossier médical (ADR-145, ADR-146).
     *
     * Le dossier d'un bébé se retrouve depuis celui de sa mère et réciproquement, **qu'il soit patient ou pas** :
     * le bébé vit d'abord dans le dossier de sa mère, et son dossier médical s'ouvre depuis sa fiche. Chaque
     * onglet est un vrai dossier médical avec son adresse ; celui où l'on se trouve est marqué.
     *
     * Les bébés non patients ne sont listés qu'à qui peut lire leur dossier : sans
     * `newborns.medical_record.view`, ne sont listés que les dossiers patients réellement ouverts — un onglet
     * qui mènerait à un refus n'est pas une navigation.
     *
     * @return list<array<string, mixed>>|null `null` : ni mère ni bébé, il n'y a rien à réunir
     */
    public function dossiers(Patient $patient, ?Episode $episode, User $user): ?array
    {
        $asBaby = PatientNewbornLink::query()
            ->where('patient_id', $patient->getKey())
            ->with(['mother:id,uuid,patient_number,first_name,last_name', 'maternityRecord.episode:id,uuid'])
            ->first();

        $record = $asBaby?->maternityRecord
            ?? ($episode?->relationLoaded('maternityRecord') ? $episode->maternityRecord : $episode?->maternityRecord()->first())
            ?? PatientNewbornLink::query()->where('mother_patient_id', $patient->getKey())->with('maternityRecord.episode:id,uuid')->first()?->maternityRecord;

        if ($record === null) {
            return null;
        }

        return $this->tabs($record, $asBaby ? $asBaby->mother : $patient, $asBaby?->newborn_uuid, $user);
    }

    /**
     * Les mêmes onglets, depuis le dossier d'un bébé qui n'est pas encore patient (ADR-146).
     *
     * @return list<array<string, mixed>>|null
     */
    public function dossiersForNewborn(MaternityRecord $record, string $newbornUuid, User $user): ?array
    {
        return $this->tabs($record, $record->episode?->patient, $newbornUuid, $user);
    }

    /**
     * @param  string|null  $currentNewbornUuid  le bébé dont on lit le dossier ; `null` : celui de la mère
     * @return list<array<string, mixed>>|null
     */
    private function tabs(MaternityRecord $record, ?Patient $mother, ?string $currentNewbornUuid, User $user): ?array
    {
        $motherEpisode = $record->relationLoaded('episode') ? $record->episode : $record->episode()->first(['id', 'uuid']);
        $links = PatientNewbornLink::query()
            ->where('maternity_record_id', $record->getKey())
            ->with('patient:id,uuid,patient_number,first_name,last_name')
            ->get()
            ->keyBy('newborn_uuid');
        $canReadRecord = $user->can('newborns.medical_record.view');

        $babies = collect($record->newborn_data['newborns'] ?? [])
            ->map(function (array $newborn, int $index) use ($links, $canReadRecord, $motherEpisode, $currentNewbornUuid): ?array {
                $uuid = $newborn['uuid'] ?? null;
                $link = $uuid ? $links->get($uuid) : null;

                // Un bébé qui n'est pas encore patient ne se lit que depuis la fiche de sa mère : sans le droit
                // de la lire, il n'est pas listé — comme une fiche vide, qui n'est pas un bébé consigné.
                if ($link === null && ! ($canReadRecord && $uuid && NewbornFiche::isFilled($newborn) && $motherEpisode)) {
                    return null;
                }

                return [
                    'key' => 'baby-'.($index + 1),
                    'label' => 'Bébé '.($index + 1),
                    'sub' => $link?->patient?->patient_number ?? 'Pas encore patient',
                    'href' => $link?->patient
                        ? "/patients/{$link->patient->uuid}/dossier-medical"
                        : $this->newbornRecordUrl($motherEpisode, $uuid),
                    'current' => $currentNewbornUuid !== null && $uuid === $currentNewbornUuid,
                ];
            })
            ->filter()
            ->values();

        if ($babies->isEmpty()) {
            return null;
        }

        return [
            [
                'key' => 'mother',
                'label' => 'Mère',
                'sub' => $mother?->patient_number,
                'href' => $motherEpisode ? "/passages/{$motherEpisode->uuid}/dossier-medical" : null,
                'current' => $currentNewbornUuid === null,
            ],
            ...$babies->all(),
        ];
    }
}
