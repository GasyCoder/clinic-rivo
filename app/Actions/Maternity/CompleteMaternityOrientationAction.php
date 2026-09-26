<?php

namespace App\Actions\Maternity;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\MaternityRecordDraft;
use App\Models\Pregnancy;
use App\Models\User;
use App\Enums\PregnancyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Termine la prise en charge Maternité — et dit ce qui vient ensuite (ADR-135).
 *
 * Deux issues, choisies par la sage-femme et jamais déduites :
 *
 * ```text
 * fin simple           la Maternité a fini. Si plus aucun service n'a la
 *                      patiente, le passage n'attend plus que la Réception
 *                      (PENDING_SETTLEMENT), comme après les Soins (ADR-054)
 * orientée vers Médecine  une orientation Médecine, source Maternité, s'ouvre
 *                      sur le même passage ; le passage reste en soins
 * ```
 *
 * Avant ce changement la fin ne faisait ni l'une ni l'autre : la patiente
 * quittait la file et son passage restait « en soins » indéfiniment, sans
 * jamais atteindre « Sorties & règlements ».
 */
class CompleteMaternityOrientationAction
{
    public function __construct(private readonly CreateEpisodeOrientationAction $createOrientation) {}

    public function execute(
        EpisodeOrientation $orientation,
        User $actor,
        bool $orientToMedicine = false,
        ?string $note = null,
    ): MaternityRecord {
        return DB::transaction(function () use ($orientation, $actor, $orientToMedicine, $note): MaternityRecord {
            $locked = EpisodeOrientation::query()
                ->with('episode.maternityRecord')
                ->lockForUpdate()
                ->findOrFail($orientation->id);

            if ($locked->destination_module !== CatalogModule::Maternity) {
                abort(404);
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'maternity_record' => 'La prise en charge Maternité n’est pas active.',
                ]);
            }

            $record = $locked->episode->maternityRecord;

            if (! $record) {
                throw ValidationException::withMessages([
                    'maternity_record' => 'Enregistrez le dossier Maternité avant de terminer.',
                ]);
            }

            // Le dossier passe en lecture seule : une saisie jamais enregistrée ne
            // doit pas rester à attendre un retour qui n'aura pas lieu.
            MaternityRecordDraft::query()->where('episode_orientation_id', $locked->getKey())->delete();

            $locked->complete($actor);
            $record->forceFill([
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->completePregnancyIfDelivered($record, $actor);

            if ($orientToMedicine) {
                $this->orientToMedicine($locked->episode, $actor, $note);
            } else {
                $this->settleIfNobodyHasThePatient($locked->episode);
            }

            return $record->fresh();
        });
    }

    /**
     * La grossesse se clôt sur le fait clinique enregistré, jamais sur now().
     * Sauvegarder un brouillon d'accouchement ne suffit pas : la transition a
     * lieu quand la prise en charge qui porte cet accouchement est terminée.
     */
    private function completePregnancyIfDelivered(MaternityRecord $record, User $actor): void
    {
        $occurredAt = $record->delivery_data['occurred_at'] ?? null;

        if ($record->pregnancy_id === null || blank($occurredAt)) {
            return;
        }

        $pregnancy = Pregnancy::query()->lockForUpdate()->findOrFail($record->pregnancy_id);

        if ($pregnancy->status !== PregnancyStatus::Ongoing) {
            return;
        }

        $deliveredAt = CarbonImmutable::parse($occurredAt);
        $pregnancy->fill([
            'status' => PregnancyStatus::Delivered,
            'delivered_at' => $deliveredAt,
            'ended_at' => $deliveredAt,
            'updated_by' => $actor->getKey(),
        ])->save();
    }

    /**
     * Une orientation Médecine déjà active est réutilisée (`active_key`) : la
     * patiente n'arrive jamais deux fois dans la file du médecin. Une
     * orientation Médecine **terminée** ne l'en empêche pas — la sage-femme
     * demande explicitement que le médecin revoie la patiente, comme un
     * ordre de soins le fait dans l'autre sens (ADR-055).
     */
    private function orientToMedicine(Episode $episode, User $actor, ?string $note): void
    {
        $note = trim((string) $note);
        $reason = 'Orientation vers Médecine à la fin de la prise en charge Maternité.';

        $this->createOrientation->execute(
            $episode,
            CatalogModule::Maternity,
            CatalogModule::Medicine,
            $actor,
            $note !== '' ? "{$reason} {$note}" : $reason,
        );
    }

    /**
     * Le parcours clinique est terminé seulement si aucun service n'a encore la
     * patiente : une césarienne demandée à Chirurgie, ou une consultation
     * Médecine encore ouverte, la retient. Même règle que la Pédiatrie et les
     * Soins ; un statut déjà avancé par la Réception n'est jamais ramené en
     * arrière.
     */
    private function settleIfNobodyHasThePatient(Episode $episode): void
    {
        if ($episode->administrative_status !== EpisodeAdministrativeStatus::InCare) {
            return;
        }

        $someoneStillHasHer = $episode->orientations()
            ->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])
            ->exists();

        if ($someoneStillHasHer) {
            return;
        }

        $episode->administrative_status = EpisodeAdministrativeStatus::PendingSettlement;
        $episode->save();
    }
}
