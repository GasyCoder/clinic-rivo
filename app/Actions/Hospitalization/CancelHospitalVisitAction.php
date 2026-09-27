<?php

namespace App\Actions\Hospitalization;

use App\Actions\Medicine\RecordConsultationOrientationAction;
use App\Enums\CareOrderStatus;
use App\Enums\CatalogModule;
use App\Enums\ConsultationStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\PrescriptionStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\CareOrder;
use App\Models\Consultation;
use App\Models\ConsultationDraft;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\EpisodeSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-163 — annuler une visite de service ouverte par erreur, ou devenue sans objet.
 *
 * Depuis l'ADR-162 plus aucune visite ne s'ouvre : tout se fait sur la page du
 * séjour. Celles d'avant restaient ouvertes sans issue et retenaient le passage
 * « en soins ». Une visite peut désormais être annulée — **pas effacée** :
 * la consultation reste en base, statut « Annulée », avec son auteur, sa date
 * et le motif (ADR-010). Ce qui y a été écrit reste lisible.
 *
 * Elle ne s'annule que tant qu'elle n'a rien produit dont un autre service ou le
 * dossier dépende : un diagnostic posé, une ordonnance active, un examen en
 * cours, des soins demandés, une sortie prononcée. Une conduite à tenir encore
 * retirable (une demande au bloc « À programmer ») est retirée avec elle ; une
 * demande déjà prise en charge l'empêche. Dans ces cas, la visite se clôture
 * (« Poursuite de l'hospitalisation », ADR-149).
 *
 * Seul le médecin qui l'a ouverte l'annule — même règle que la remise en file
 * (ADR-127). Le patient reste hospitalisé : l'annulation ne touche ni le
 * séjour, ni le lit.
 */
class CancelHospitalVisitAction
{
    public const DEFAULT_REASON = 'Visite de service annulée.';

    public function __construct(
        private readonly RecordConsultationOrientationAction $recordOrientation,
        private readonly Auditor $auditor,
    ) {}

    public function execute(HospitalStay $stay, EpisodeOrientation $visit, ?string $reason, User $actor): Consultation
    {
        return DB::transaction(function () use ($stay, $visit, $reason, $actor): Consultation {
            $orientation = EpisodeOrientation::query()->with('episode')->lockForUpdate()->findOrFail($visit->getKey());

            if (! self::isVisit($orientation) || $orientation->episode_id !== $stay->episode_id) {
                throw ValidationException::withMessages(['visit' => 'Cette consultation n’est pas une visite de service de ce séjour.']);
            }

            if ($orientation->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['visit' => 'Ce passage est clos.']);
            }

            /** @var Consultation|null $consultation */
            $consultation = Consultation::query()
                ->where('episode_orientation_id', $orientation->getKey())
                ->lockForUpdate()
                ->first();

            if (! $consultation || $orientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages(['visit' => 'Cette visite n’est plus en cours.']);
            }

            if ($orientation->accepted_by !== null && $orientation->accepted_by !== $actor->getKey()) {
                throw ValidationException::withMessages(['visit' => 'Seul le médecin qui a ouvert cette visite peut l’annuler.']);
            }

            $blockers = self::blockers($consultation);

            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'visit' => 'Cette visite ne s’annule plus — '.implode(' ', $blockers).' Clôturez-la : « Poursuite de l’hospitalisation ».',
                ]);
            }

            $reason = filled($reason) ? trim($reason) : self::DEFAULT_REASON;

            // La conduite à tenir encore retirable part avec la visite
            // (ADR-084) : une demande au bloc « À programmer » est retirée.
            $this->recordOrientation->clear($consultation, $actor, $reason);

            $consultation->update([
                'status' => ConsultationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => mb_substr($reason, 0, 500),
            ]);
            ConsultationDraft::query()->where('episode_orientation_id', $orientation->getKey())->delete();
            $orientation->cancelTakenUp($actor);

            $episode = $orientation->episode->fresh();

            // Le patient est toujours au lit : l'ouverture de la visite l'avait
            // remis « en soins » (ADR-148), l'annulation ne le fait pas sortir.
            if ($stay->fresh()->isActive() && $episode->medical_status !== EpisodeMedicalStatus::Hospitalized) {
                $episode->update(['medical_status' => EpisodeMedicalStatus::Hospitalized]);
            }

            EpisodeSettlement::advanceWhenNoServiceLeft($episode->fresh());

            $this->auditor->record(
                'hospitalization.visit.cancel',
                entity: $consultation,
                oldValues: ['status' => ConsultationStatus::InProgress->value],
                newValues: ['status' => ConsultationStatus::Cancelled->value, 'reason' => $reason, 'hospital_stay' => $stay->uuid],
            );

            return $consultation->fresh();
        });
    }

    /** Une orientation Médecine ouverte depuis le séjour : c'est ce qu'était une visite de service (ADR-148). */
    public static function isVisit(EpisodeOrientation $orientation): bool
    {
        return $orientation->destination_module === CatalogModule::Medicine
            && $orientation->source_module === CatalogModule::Hospitalization;
    }

    /**
     * Ce qui empêche d'annuler la visite, nommé pour l'écran comme pour le refus.
     * Vide : rien de ce qu'elle a produit ne doit survivre à son annulation.
     *
     * @return list<string>
     */
    public static function blockers(Consultation $consultation): array
    {
        $blockers = [];

        if ($consultation->diagnoses()->whereDoesntHave('cancellation')->exists()) {
            $blockers[] = 'Elle porte un diagnostic.';
        }

        if ($consultation->prescriptions()->where('status', PrescriptionStatus::Active->value)->exists()) {
            $blockers[] = 'Elle porte une ordonnance active.';
        }

        if ($consultation->labRequests()->whereNull('cancelled_at')->exists()
            || $consultation->imagingRequests()->whereNull('cancelled_at')->exists()) {
            $blockers[] = 'Des examens demandés sont en cours.';
        }

        if (CareOrder::query()
            ->where('consultation_id', $consultation->getKey())
            ->where('status', '!=', CareOrderStatus::Cancelled->value)
            ->exists()) {
            $blockers[] = 'Des soins ont été demandés.';
        }

        if ($consultation->medicalDischarge()->exists()) {
            $blockers[] = 'Une sortie médicale y a été prononcée.';
        }

        $active = $consultation->orientations()
            ->whereNotNull('active_key')
            ->with(['surgicalRequest', 'episodeOrientation'])
            ->first();

        if ($active) {
            $surgery = $active->surgicalRequest;

            if ($surgery && ! in_array($surgery->status, [SurgicalRequestStatus::Pending, SurgicalRequestStatus::Cancelled], true)) {
                $blockers[] = 'Le bloc a déjà programmé la demande qu’elle a transmise.';
            } elseif (! $surgery && $active->episodeOrientation
                && ! in_array($active->episodeOrientation->status, [EpisodeOrientationStatus::Pending, EpisodeOrientationStatus::Cancelled], true)) {
                $blockers[] = sprintf('Le service %s a déjà pris en charge la demande qu’elle a transmise.', $active->episodeOrientation->destination_module->label());
            } elseif ($active->medical_discharge_id !== null) {
                $blockers[] = 'Une sortie médicale y est rattachée.';
            }
        }

        return array_values(array_unique($blockers));
    }
}
