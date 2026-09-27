<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\MaternityRecordDraft;
use App\Models\PatientNewbornLink;
use App\Models\User;
use App\Services\Maternity\ActivePregnancyResolver;
use App\Services\Maternity\PregnancyDatingService;
use App\Support\NewbornFiche;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SaveMaternityRecordAction
{
    public function __construct(
        private readonly ActivePregnancyResolver $pregnancies,
        private readonly PregnancyDatingService $dating,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): MaternityRecord
    {
        return DB::transaction(function () use ($orientation, $data, $actor): MaternityRecord {
            $locked = EpisodeOrientation::query()->with(['episode.maternityRecord'])->lockForUpdate()->findOrFail($orientation->id);
            if ($locked->destination_module !== CatalogModule::Maternity) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas la Maternité.');
            }
            if ($locked->status !== EpisodeOrientationStatus::InProgress || $locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['maternity_record' => 'Le dossier est modifiable uniquement pendant une prise en charge Maternité active.']);
            }

            $record = $locked->episode->maternityRecord;

            // ADR-204 — un enregistrement automatique arrivé après la fin de la
            // consultation n'y écrit jamais : un dossier terminé est clos, même
            // si une nouvelle prise en charge Maternité s'ouvre sur ce passage.
            if ($record?->isFinalized()) {
                throw ValidationException::withMessages(['maternity_record' => 'Ce dossier Maternité est terminé : il n’est plus modifiable.']);
            }

            $pregnancy = $this->pregnancies->resolve($locked, $record, $data, $actor);
            $pregnancyData = is_array($data['pregnancy_data'] ?? null) ? $data['pregnancy_data'] : [];
            $pregnancy = $this->pregnancies->syncInitialDating($pregnancy, $pregnancyData, $actor);
            $pregnancy = $this->pregnancies->syncClinicalDetails($pregnancy, $pregnancyData, $actor);

            unset($data['pregnancy_choice'], $data['pregnancy_uuid']);
            // Un enregistrement peut porter le parcours, jamais l'effacer (ADR-204).
            if (array_key_exists('encounter_type', $data) && $data['encounter_type'] === null) {
                unset($data['encounter_type']);
            }
            $data['pregnancy_id'] = $pregnancy->getKey();
            // Compatibilité progressive : le JSON historique reste lisible,
            // mais sa valeur vient désormais de la Pregnancy longitudinale.
            $data['pregnancy_data'] = $this->pregnancies->consultationSnapshot($pregnancy);

            $age = $this->dating->gestationalAge($pregnancy, $locked->episode->started_at ?? now());
            if ($age !== null) {
                $data['gestational_age_weeks'] = $age['weeks'];
                $data['gestational_age_days'] = $age['days'];
                $data['prenatal_data'] = is_array($data['prenatal_data'] ?? null) ? $data['prenatal_data'] : [];
                $data['prenatal_data']['gestational_age_weeks'] = $age['weeks'];
                $data['prenatal_data']['gestational_age_days'] = $age['days'];
            } elseif (is_array($data['prenatal_data'] ?? null)) {
                $data['gestational_age_weeks'] = $data['prenatal_data']['gestational_age_weeks'] ?? null;
                $data['gestational_age_days'] = $data['prenatal_data']['gestational_age_days'] ?? 0;
            }

            if (isset($data['newborn_data'])) {
                $data['newborn_data'] = $this->guardNewbornIdentities($record, $data['newborn_data']);
            }

            if ($record) {
                $record->fill($data + ['updated_by' => $actor->id])->save();
            } else {
                $record = MaternityRecord::query()->create([
                    'episode_id' => $locked->episode_id,
                    'episode_orientation_id' => $locked->id,
                    ...$data,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            // La saisie est devenue un vrai dossier : sa section de brouillon
            // n'a plus de raison de survivre, et ne doit jamais être restaurée
            // par-dessus ce qui vient d'être enregistré (ADR-073). Les autres
            // sections — le panier d'actes, la césarienne — restent : un
            // enregistrement automatique du dossier ne les emporte pas (ADR-204).
            MaternityRecordDraft::forgetSection($locked->getKey(), $actor->getKey(), 'record');

            return $record->fresh(['procedures.performer']);
        });
    }

    /**
     * Un bébé qui a son dossier patient garde son identité (ADR-144).
     *
     * Les fiches n'ont pas d'identifiant propre : le `uuid` que le serveur a donné à un bébé voyage
     * avec sa fiche, et c'est lui que le lien patient désigne.
     *
     *  - un `uuid` que ce dossier ne connaît pas est retiré : un navigateur n'invente pas d'identité,
     *    sinon une fiche pourrait se faire passer pour le bébé d'un autre dossier ;
     *  - retirer la fiche d'un bébé qui a déjà son dossier est refusé, et le dit — son suivi resterait
     *    sans fiche, et le lien ne désignerait plus rien.
     *
     * @param  array<string, mixed>  $newbornData
     * @return array<string, mixed>
     */
    private function guardNewbornIdentities(?MaternityRecord $record, array $newbornData): array
    {
        $current = collect($record?->newborn_data['newborns'] ?? []);
        $known = $current->pluck('uuid')->filter()->all();

        // Un `uuid` que ce dossier ne connaît pas est retiré : un navigateur n'invente pas d'identité.
        $entries = collect($newbornData['newborns'] ?? [])
            ->map(function (array $newborn) use ($known): array {
                if (isset($newborn['uuid']) && ! in_array($newborn['uuid'], $known, true)) {
                    unset($newborn['uuid']);
                }

                return $newborn;
            });
        $kept = $entries->pluck('uuid')->filter()->all();

        $newbornData['newborns'] = $entries
            ->map(function (array $newborn, int $index) use ($current, $kept): array {
                // ADR-146 — un bébé consigné a son identité dès la saisie : c'est elle que la Réception
                // retrouve dans l'arborescence de sa mère. Une fiche encore vide n'en a pas.
                if (blank($newborn['uuid'] ?? null) && NewbornFiche::isFilled($newborn)) {
                    // Un écran qui n'a pas repris l'identité déjà donnée à cette fiche ne la change pas :
                    // le bébé garde la sienne, tant que personne d'autre ne la porte.
                    $existing = $current->get($index)['uuid'] ?? null;

                    $newborn['uuid'] = $existing && ! in_array($existing, $kept, true) ? $existing : (string) Str::uuid();
                }

                return $newborn;
            })
            ->all();

        if ($record !== null) {
            $submitted = collect($newbornData['newborns'])->pluck('uuid')->filter();
            $missing = PatientNewbornLink::query()
                ->where('maternity_record_id', $record->getKey())
                ->whereNotIn('newborn_uuid', $submitted->all() ?: [''])
                ->exists();

            if ($missing) {
                throw ValidationException::withMessages([
                    'newborn_data' => 'Un bébé qui a déjà son dossier patient ne peut pas être retiré de ce dossier Maternité.',
                ]);
            }
        }

        return $newbornData;
    }
}
