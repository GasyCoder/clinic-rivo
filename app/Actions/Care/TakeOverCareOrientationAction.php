<?php

namespace App\Actions\Care;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reprendre un patient pris en charge par un collègue (ADR-167).
 *
 * L'ADR-085 réserve la fin des Soins et le transfert vers Médecine au soignant
 * qui a pris le patient. Quand ce soignant n'est plus là — fin de garde,
 * mauvais compte, patient pris par erreur —, personne ne pouvait plus décider
 * la suite. La reprise transfère cette responsabilité, et seulement elle :
 *
 * - `accepted_by` devient le compte qui reprend ; l'ancien soignant ne peut
 *   plus terminer ni transmettre (il garde la correction de la fiche, ADR-092) ;
 * - `accepted_at` n'est pas réécrit : c'est le début réel des soins, et la
 *   remise en file (ADR-122) doit continuer de voir le travail déjà fait ;
 * - le motif est obligatoire, et l'audit garde l'ancien et le nouveau
 *   soignant — l'historique complet des reprises y reste.
 */
class TakeOverCareOrientationAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @throws ValidationException */
    public function execute(EpisodeOrientation $orientation, User $actor, string $reason): EpisodeOrientation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indiquez pourquoi vous reprenez ce patient.']);
        }

        return DB::transaction(function () use ($orientation, $actor, $reason): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with(['episode', 'acceptedBy:id,name'])
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Care) {
                throw ValidationException::withMessages(['reason' => 'Cette orientation ne concerne pas le service Soins.']);
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages(['reason' => match ($locked->status) {
                    EpisodeOrientationStatus::Pending => 'Personne n’a encore pris ce patient en charge : prenez-le directement dans la file.',
                    EpisodeOrientationStatus::Completed => 'Les soins de ce patient sont déjà terminés : il n’y a plus de prise en charge à reprendre.',
                    default => 'Cette prise en charge Soins a été annulée.',
                }]);
            }

            if ($locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['reason' => 'Ce passage est clos : sa prise en charge ne peut plus être reprise.']);
            }

            if ($locked->accepted_by === $actor->getKey()) {
                throw ValidationException::withMessages(['reason' => 'Vous avez déjà ce patient en charge.']);
            }

            $previous = $locked->accepted_by;
            $before = [
                'accepted_by' => $previous,
                'accepted_by_name' => $locked->acceptedBy?->name,
                'taken_over_at' => $locked->taken_over_at?->toIso8601String(),
                'taken_over_from' => $locked->taken_over_from,
                'takeover_reason' => $locked->takeover_reason,
            ];

            $locked->accepted_by = $actor->getKey();
            $locked->taken_over_from = $previous;
            $locked->taken_over_at = now();
            $locked->takeover_reason = $reason;
            $locked->save();

            $this->auditor->record(
                'care.orientation.take_over',
                entity: $locked,
                oldValues: $before,
                newValues: [
                    'accepted_by' => $actor->getKey(),
                    'accepted_by_name' => $actor->name,
                    'taken_over_at' => $locked->taken_over_at->toIso8601String(),
                    'taken_over_from' => $previous,
                    'takeover_reason' => $reason,
                ],
            );

            return $locked->fresh(['episode.patient', 'acceptedBy', 'takenOverFrom']);
        });
    }
}
