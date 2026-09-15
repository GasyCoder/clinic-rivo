<?php

namespace App\Actions\Medicine;

use App\Enums\EpisodeStatus;
use App\Models\CareRecord;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Support\VitalSignRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le médecin corrige une constante manifestement fausse.
 *
 * ADR-093, décision explicite du propriétaire (2026-09-15) : une température
 * saisie 32 °C au lieu de 36,2 parvient au médecin telle quelle et lui reste
 * inutilisable. Il doit pouvoir la rectifier sans attendre que le soignant
 * qui l'a saisie revienne de son service.
 *
 * Le périmètre est volontairement étroit — *les constantes, et rien d'autre*.
 * Ce n'est pas un raccourci pour tenir la fiche Soins depuis la Médecine :
 *
 *   constantes        corrigeables ici
 *   actes réalisés    non — append-only, et un acte crée un BillableItem
 *   consommables      non — une déclaration sort du stock Pharmacie
 *   allergies         non — dossier permanent, patients.medical_history.manage
 *   transmission      non — c'est la parole des Soins, pas celle du médecin
 *
 * Laisser Médecine écrire ces quatre-là ferait naître, depuis un écran de
 * consultation, une prestation à facturer ou une sortie de stock : deux
 * circuits qui appartiennent à la Réception et à la Pharmacie.
 *
 * La correction écrase réellement la valeur — c'est ce qui a été demandé et
 * ce qui amende l'ADR-077. Ce qui la rend acceptable est la trace :
 * `CareRecord` porte `Auditable`, qui conserve l'ancienne **et** la nouvelle
 * valeur, avec son auteur et sa date. Le 32 °C n'est pas perdu, il cesse
 * seulement d'être présenté comme la mesure du patient.
 */
class CorrectCareRecordVitalsAction
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): CareRecord
    {
        return DB::transaction(function () use ($orientation, $data, $actor): CareRecord {
            // Verrouillé avant lecture : deux corrections simultanées sur le
            // même passage sont sérialisées par la base, et la seconde lit le
            // résultat de la première au lieu d'une page périmée.
            $episode = $orientation->episode()->lockForUpdate()->firstOrFail();

            if ($episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'care_record' => 'Ce passage est clôturé : ses constantes ne peuvent plus être corrigées.',
                ]);
            }

            $record = CareRecord::query()
                ->where('episode_id', $episode->getKey())
                ->lockForUpdate()
                ->first();

            // Médecine corrige une mesure ; elle n'en invente pas une. Sans
            // fiche Soins il n'y a rien à rectifier, et créer ici la première
            // fiche du passage reviendrait à signer un relevé que personne
            // n'a pris.
            if ($record === null) {
                throw ValidationException::withMessages([
                    'care_record' => 'Aucune fiche Soins n’existe pour ce passage : il n’y a aucune constante à corriger.',
                ]);
            }

            $knownDiabetes = array_key_exists('known_diabetes', $data) ? $data['known_diabetes'] : null;
            $height = $data['height_cm'] ?? null;
            $weight = $data['weight_kg'] ?? null;

            $record->fill([
                'blood_group' => $data['blood_group'] ?? null,
                'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                'heart_rate' => $data['heart_rate'] ?? null,
                'spo2' => $data['spo2'] ?? null,
                'temperature_celsius' => $data['temperature_celsius'] ?? null,
                'known_diabetes' => $knownDiabetes,
                // Même règle que la fiche Soins : la note ne survit pas à un
                // « non » ou à une absence de réponse.
                'diabetes_note' => $knownDiabetes === true
                    ? (filled($data['diabetes_note'] ?? null) ? trim((string) $data['diabetes_note']) : null)
                    : null,
                'height_cm' => $height,
                'weight_kg' => $weight,
                // Jamais la valeur du navigateur : l'IMC est recalculé ici,
                // par le même code que la fiche Soins.
                'bmi' => VitalSignRules::bmi($height, $weight),
                'smoker' => array_key_exists('smoker', $data) ? $data['smoker'] : null,
                'alcohol' => array_key_exists('alcohol', $data) ? $data['alcohol'] : null,
                'updated_by' => $actor->getKey(),
            ]);

            // Rien n'a bougé : pas d'écriture, donc pas de ligne d'audit
            // annonçant une correction qui n'a pas eu lieu.
            if ($record->isDirty()) {
                $record->save();
            }

            return $record;
        });
    }
}
