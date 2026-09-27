<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalCareLevel;
use App\Enums\HospitalStayStatus;
use App\Models\EpisodeOrientation;
use App\Models\HospitalizationRequest;
use App\Models\HospitalStay;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 — l'admission est automatique : dès que le médecin transmet sa
 * demande d'hospitalisation, le séjour commence et le passage devient
 * « Hospitalisé » (CDC §32). Appelée dans la transaction de
 * `CreateHospitalizationRequestAction`, jamais seule.
 */
class AdmitHospitalStayAction
{
    public function execute(HospitalizationRequest $request, EpisodeOrientation $orientation, User $actor): HospitalStay
    {
        $episode = $request->episode()->lockForUpdate()->firstOrFail();

        if (HospitalStay::query()->where('active_key', HospitalStay::activeKeyFor($episode))->exists()) {
            throw ValidationException::withMessages([
                'hospitalization' => 'Ce patient est déjà hospitalisé pour ce passage.',
            ]);
        }

        // L'orientation qui porte la demande est prise en charge au même
        // instant : le patient n'attend dans aucune file.
        if ($orientation->status === EpisodeOrientationStatus::Pending) {
            $orientation->accept($actor);
        }

        $stay = HospitalStay::query()->create([
            'episode_id' => $episode->getKey(),
            'hospitalization_request_id' => $request->getKey(),
            'episode_orientation_id' => $orientation->getKey(),
            'status' => HospitalStayStatus::Active,
            'service' => $request->requested_service,
            'admitted_at' => now(),
            'admitted_by' => $actor->getKey(),
            'active_key' => HospitalStay::activeKeyFor($episode),
        ]);

        // ADR-161 — l'admission est le premier emplacement du patient. La
        // chambre / le lit se complètent ensuite ; une mutation en ouvre un autre.
        $stay->movements()->create([
            'service' => $stay->service,
            'care_level' => HospitalCareLevel::Standard,
            'started_at' => $stay->admitted_at,
            'moved_by' => $actor->getKey(),
        ]);

        $episode->update(['medical_status' => EpisodeMedicalStatus::Hospitalized]);

        return $stay;
    }
}
