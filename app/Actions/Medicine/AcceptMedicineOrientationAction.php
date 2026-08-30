<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AcceptMedicineOrientationAction
{
    public function execute(EpisodeOrientation $orientation, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($orientation, $actor): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with('episode.serviceRequests')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Medicine) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas le service Médecine.');
            }

            if ($locked->status === EpisodeOrientationStatus::Pending) {
                $locked->accept($actor);
            } elseif ($locked->status !== EpisodeOrientationStatus::InProgress) {
                throw new InvalidArgumentException('Cette orientation Médecine n’est plus active.');
            }

            $locked->episode->startCare();
            $locked->episode->update(['medical_status' => EpisodeMedicalStatus::InCare]);

            // Deliberately never seeded from $locked->reason: that field is
            // the EpisodeOrientation's technical routing note (e.g.
            // "Orientation vers Médecine selon le parcours planifié."), not
            // a clinical motif — the doctor must enter the real one.
            $initialReason = $locked->episode->serviceRequests->pluck('designation')->filter()->join(' · ')
                ?: 'Motif à préciser';

            Consultation::query()->firstOrCreate(
                ['episode_orientation_id' => $locked->getKey()],
                [
                    'episode_id' => $locked->episode_id,
                    'doctor_id' => $locked->accepted_by ?? $actor->getKey(),
                    'reason' => $initialReason,
                    'consulted_at' => $locked->accepted_at ?? now(),
                ],
            );

            return $locked->fresh(['episode.patient', 'consultation']);
        });
    }
}
