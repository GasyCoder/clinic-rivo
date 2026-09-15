<?php

namespace App\Actions\Medicine;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationStep;
use App\Enums\ConsultationStepStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rouvrir une consultation clôturée — le mécanisme tracé que l'ADR-076
 * annonçait sans le construire :
 *
 *   « Après clôture, tous les chemins d'écriture ordinaires refusent.
 *     Conformément à l'ADR-010, une correction ultérieure exigera son propre
 *     mécanisme tracé ; rien n'est réécrit silencieusement. »
 *
 * Le cas réel : un ECG demandé le matin, la consultation clôturée, le
 * résultat qui arrive l'après-midi. Le médecin doit pouvoir consigner ce que
 * ce résultat change — sans quoi le compte rendu existe et la conclusion du
 * dossier l'ignore pour toujours.
 *
 * Cette action **défait exactement** ce que `CompleteConsultationAction` a
 * fait, et rien d'autre. En particulier elle ne supprime aucune donnée
 * clinique : une sortie médicale déjà prononcée reste prononcée, les
 * diagnostics restent append-only, et le statut médical du passage n'est pas
 * ramené en arrière. Rouvrir, ce n'est pas annuler.
 */
class ReopenConsultationAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @throws ValidationException
     */
    public function execute(Consultation $consultation, string $reason, User $actor): Consultation
    {
        return DB::transaction(function () use ($consultation, $reason, $actor): Consultation {
            /** @var Consultation $locked */
            $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());

            if ($locked->status !== ConsultationStatus::Completed) {
                throw ValidationException::withMessages([
                    'reason' => 'Seule une consultation clôturée peut être rouverte.',
                ]);
            }

            $orientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->find($locked->episode_orientation_id);

            $episode = $orientation?->episode;

            if (! $episode) {
                throw ValidationException::withMessages([
                    'reason' => 'Ce passage est introuvable : la consultation ne peut pas être rouverte.',
                ]);
            }

            // La limite, décidée avec l'équipe : une fois la sortie
            // administrative prononcée (ADR-090), le compte est soldé et une
            // créance a pu être enregistrée. Rouvrir ferait réapparaître un
            // passage déjà facturé dans une file clinique, et rien au CDC ne
            // dit ce que deviendraient la facture et la créance.
            if ($episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'reason' => 'Ce passage a été clos par la Réception : la consultation ne peut plus être rouverte.',
                ]);
            }

            $before = [
                'consultation_status' => $locked->status->value,
                'orientation_status' => $orientation->status->value,
                'administrative_status' => $episode->administrative_status?->value,
            ];

            $locked->update([
                'status' => ConsultationStatus::InProgress,
                'completed_at' => null,
                'completed_by' => null,
            ]);

            // L'étape Clôture est résolue *par* la clôture (ADR-084) : la
            // rouvrir, c'est la dé-résoudre, sans quoi l'assistant afficherait
            // une consultation ouverte dont la dernière étape est validée.
            $locked->steps()
                ->where('step', ConsultationStep::Closure->value)
                ->update([
                    'status' => ConsultationStepStatus::NotStarted->value,
                    'completed_at' => null,
                    'completed_by' => null,
                ]);

            if ($orientation->status === EpisodeOrientationStatus::Completed) {
                $orientation->forceFill([
                    'status' => EpisodeOrientationStatus::InProgress,
                    'completed_at' => null,
                    'completed_by' => null,
                ])->save();
            }

            // Le passage retourne en prise en charge : le laisser en attente
            // de règlement le montrerait à la Réception comme prêt à sortir
            // pendant qu'un médecin y écrit encore. Un statut que la
            // Réception a déjà fait avancer plus loin n'est jamais ramené en
            // arrière — c'est la garde symétrique de l'ADR-054.
            if ($episode->administrative_status === EpisodeAdministrativeStatus::PendingSettlement) {
                $episode->administrative_status = EpisodeAdministrativeStatus::InCare;
                $episode->saveQuietly();
            }

            $this->auditor->record(
                'consultation.reopen',
                entity: $locked,
                oldValues: $before,
                newValues: [
                    'consultation_status' => $locked->status->value,
                    'orientation_status' => $orientation->fresh()->status->value,
                    'administrative_status' => $episode->administrative_status?->value,
                ],
                reason: $reason,
                module: 'medicine',
                actor: $actor,
            );

            return $locked->fresh(['steps']);
        });
    }
}
