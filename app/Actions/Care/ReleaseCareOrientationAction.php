<?php

namespace App\Actions\Care;

use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\CareConsumableRequest;
use App\Models\CareOrderItem;
use App\Models\CareRecord;
use App\Models\CareRecordDraft;
use App\Models\CareRecordProcedure;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remettre en file un patient pris en charge par erreur (ADR-122).
 *
 * Un clic au mauvais endroit ne doit pas obliger le soignant à garder un
 * patient qu'il n'a pas commencé à soigner. Le patient retrouve **sa place**
 * sans rien recalculer : l'ordre de la file est celui de `oriented_at`, que la
 * prise en charge n'a jamais touché.
 *
 * Réservé au soignant qui a pris le patient, tant qu'**aucun travail** n'a été
 * enregistré depuis : dès qu'un acte, une constante, du matériel ou un « non
 * réalisé » existe, le patient a réellement été soigné et ne se « rend » plus —
 * la suite passe par les soins terminés, tracés (ADR-092). Une saisie non
 * enregistrée n'est qu'un brouillon jetable (ADR-073) : elle est écartée.
 */
class ReleaseCareOrientationAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @throws ValidationException */
    public function execute(EpisodeOrientation $orientation, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($orientation, $actor): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Care) {
                throw ValidationException::withMessages(['orientation' => 'Cette orientation ne concerne pas le service Soins.']);
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages(['orientation' => 'Ce patient n’est pas en cours de prise en charge aux Soins.']);
            }

            if ($locked->accepted_by !== null && $locked->accepted_by !== $actor->getKey()) {
                throw ValidationException::withMessages(['orientation' => 'Seule la personne qui a pris ce patient en charge peut le remettre en file.']);
            }

            if ($locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['orientation' => 'Ce passage est clos : le patient ne peut plus être remis en file.']);
            }

            if ($this->hasWork($locked)) {
                throw ValidationException::withMessages(['orientation' => 'Des soins ont déjà été enregistrés pour ce patient : il ne peut plus être remis en file.']);
            }

            $before = [
                'status' => $locked->status->value,
                'accepted_by' => $locked->accepted_by,
                'accepted_at' => $locked->accepted_at?->toIso8601String(),
            ];

            CareRecordDraft::query()->where('episode_orientation_id', $locked->getKey())->delete();

            $locked->status = EpisodeOrientationStatus::Pending;
            $locked->accepted_by = null;
            $locked->accepted_at = null;
            $locked->save();

            $this->stopCareIfNobodyElseIsWorking($locked);

            $this->auditor->record(
                'care.orientation.release',
                entity: $locked,
                oldValues: $before,
                newValues: ['status' => $locked->status->value, 'accepted_by' => null, 'accepted_at' => null],
            );

            return $locked->fresh(['episode.patient']);
        });
    }

    /** Vrai dès que quelque chose a été enregistré depuis la prise en charge. */
    private function hasWork(EpisodeOrientation $orientation): bool
    {
        $since = $orientation->accepted_at;
        $episodeId = $orientation->episode_id;

        $record = CareRecord::query()->where('episode_id', $episodeId)->first();

        if ($record !== null) {
            if ($since === null || $record->updated_at >= $since) {
                return true;
            }

            if (CareRecordProcedure::query()->where('care_record_id', $record->getKey())
                ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('performed_at', '>=', $since))
                ->exists()) {
                return true;
            }
        }

        return CareConsumableRequest::query()->where('care_orientation_id', $orientation->getKey())->exists()
            || CareOrderItem::query()
                ->whereHas('careOrder', fn ($q) => $q->where('care_orientation_id', $orientation->getKey()))
                ->where('not_performed_at', '>=', $since ?? now()->subYears(100))
                ->exists();
    }

    /**
     * La prise en charge avait fait passer le passage « en soins » : si plus
     * personne ne travaille dessus, il redevient simplement orienté.
     */
    private function stopCareIfNobodyElseIsWorking(EpisodeOrientation $orientation): void
    {
        $episode = $orientation->episode;

        if ($episode->administrative_status !== EpisodeAdministrativeStatus::InCare) {
            return;
        }

        $othersWorking = EpisodeOrientation::query()
            ->where('episode_id', $episode->getKey())
            ->whereKeyNot($orientation->getKey())
            ->where('status', EpisodeOrientationStatus::InProgress->value)
            ->exists();

        if (! $othersWorking) {
            $episode->administrative_status = EpisodeAdministrativeStatus::Oriented;
            $episode->save();
        }
    }
}
