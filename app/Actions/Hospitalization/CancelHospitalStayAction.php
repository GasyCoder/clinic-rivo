<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\SurgicalRequestOrigin;
use App\Models\HospitalStay;
use App\Models\MedicalReferral;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 — le médecin retire sa demande d'hospitalisation (changement de
 * conduite à tenir, ADR-084) : le séjour ouvert automatiquement est annulé,
 * jamais supprimé.
 *
 * ADR-163 — refusé dès que le patient a **réellement séjourné**. L'ADR-113 ne
 * regardait que la fiche de régime ; depuis que tout se fait sur la page du
 * séjour (ADR-162), une note du jour, une ordonnance, un examen, une demande de
 * soins, un relevé, un diagnostic, un passage au bloc ou un transfert disent la
 * même chose. Annuler le séjour les laisserait rattachés à un séjour qui n'a
 * « jamais existé » : c'est la sortie médicale qui le termine.
 */
class CancelHospitalStayAction
{
    public function execute(HospitalStay $stay, string $reason, User $actor): void
    {
        $locked = HospitalStay::query()->with(['episode', 'episodeOrientation'])->lockForUpdate()->findOrFail($stay->getKey());

        if (! $locked->isActive()) {
            return;
        }

        $activity = self::activity($locked);

        if ($activity !== []) {
            throw ValidationException::withMessages([
                'orientation' => sprintf(
                    'Le patient a déjà séjourné (%s) : le séjour ne s’annule plus, il se termine par une sortie médicale.',
                    implode(', ', $activity),
                ),
            ]);
        }

        $locked->update([
            'status' => HospitalStayStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->getKey(),
            'cancellation_reason' => mb_substr($reason, 0, 500),
            'active_key' => null,
        ]);
        $locked->closeCurrentMovement();

        // L'orientation a été prise en charge par l'admission automatique.
        $orientation = $locked->episodeOrientation;

        if ($orientation && $orientation->status === EpisodeOrientationStatus::InProgress) {
            $orientation->cancelTakenUp($actor);
        }

        if ($locked->episode->medical_status === EpisodeMedicalStatus::Hospitalized) {
            $locked->episode->update(['medical_status' => EpisodeMedicalStatus::InCare]);
        }
    }

    /**
     * Ce que le séjour a déjà produit, nommé pour le message. Vide : le patient
     * n'a jamais réellement été pris en charge au lit.
     *
     * Une demande retirée depuis (ordonnance annulée, examen retiré, transfert
     * ou bloc annulé) compte encore : elle prouve que le séjour a eu lieu.
     *
     * @return list<string>
     */
    public static function activity(HospitalStay $stay): array
    {
        $checks = [
            'fiche de régime' => $stay->dietEntries()->exists(),
            'note du jour' => $stay->notes()->exists(),
            'ordonnance' => $stay->prescriptions()->exists(),
            'analyse' => $stay->labRequests()->exists(),
            'imagerie' => $stay->imagingRequests()->exists(),
            'demande de soins' => $stay->careOrders()->exists(),
            'relevé de surveillance' => $stay->vitalReadings()->exists(),
            'diagnostic du séjour' => $stay->diagnoses()->exists(),
            'changement de service ou de lit' => $stay->movements()->count() > 1,
            'passage au bloc' => SurgicalRequest::query()
                ->where('episode_id', $stay->episode_id)
                ->where('origin', SurgicalRequestOrigin::Hospitalization->value)
                ->exists(),
            'demande de transfert' => MedicalReferral::query()
                ->where('hospital_stay_id', $stay->getKey())
                ->exists(),
        ];

        return array_keys(array_filter($checks));
    }
}
