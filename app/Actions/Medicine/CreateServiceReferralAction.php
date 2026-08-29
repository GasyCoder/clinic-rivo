<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A lightweight DEMANDE toward a destination with no dedicated workspace
 * yet (Maternité, Hospitalisation, Transfert, Pédiatrie): only
 * EpisodeOrientation is reused, its `reason` carrying the structured text
 * the doctor entered. Never a generic MedicalOrder model — once one of
 * these destinations gets a real workspace, its own request model can be
 * introduced then, exactly as CareOrder/LabRequest were.
 */
class CreateServiceReferralAction
{
    private const DECISION_BY_DESTINATION = [
        'MATERNITY' => ConsultationDecision::MaternityReferral,
        'HOSPITALIZATION' => ConsultationDecision::Hospitalization,
        'TRANSFER' => ConsultationDecision::ExternalTransfer,
        'PEDIATRICS' => ConsultationDecision::PediatricsReferral,
    ];

    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
    ) {}

    public function execute(
        Consultation $consultation,
        CatalogModule $destination,
        string $reason,
        User $actor,
    ): EpisodeOrientation {
        return DB::transaction(function () use ($consultation, $destination, $reason, $actor): EpisodeOrientation {
            $lockedConsultation = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $medicineOrientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($lockedConsultation->episode_orientation_id);

            if ($medicineOrientation->destination_module !== CatalogModule::Medicine
                || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'referral' => 'Cette consultation n’est plus active.',
                ]);
            }

            $orientation = $this->createOrientation->execute(
                $medicineOrientation->episode,
                CatalogModule::Medicine,
                $destination,
                $actor,
                $reason,
            );

            $decision = self::DECISION_BY_DESTINATION[$destination->value] ?? null;

            if ($decision) {
                $lockedConsultation->update(['decision' => $decision]);
            }

            return $orientation;
        });
    }
}
