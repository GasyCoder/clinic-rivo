<?php

namespace App\Actions\Hospitalization;

use App\Actions\Surgery\WithdrawSurgicalRequestAction;
use App\Enums\ConsultationOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\SurgicalRequestOrigin;
use App\Enums\SurgicalRequestStatus;
use App\Models\ConsultationOrientation;
use App\Models\Episode;
use App\Models\HospitalStay;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\EpisodeSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-163 — revenir sur un « Transférer au bloc ».
 *
 * Le patient n'a jamais quitté son lit (ADR-160) : il n'y a rien à « faire
 * revenir ». Annuler, c'est retirer la demande tant que le bloc ne l'a pas
 * programmée — la règle unique de `WithdrawSurgicalRequestAction`.
 *
 * Deux chemins mènent un patient hospitalisé au bloc, et chacun se défait ici :
 *
 *   - le séjour lui-même (origine « Hospitalisation ») ;
 *   - la conduite à tenir d'une consultation du passage, par exemple une visite
 *     de service ouverte avant l'ADR-162 (origine « Médecine ») : sa conduite à
 *     tenir est alors annulée avec la demande, sinon elle resterait « transmise »
 *     vers un bloc qui n'attend plus personne.
 *
 * Une demande née à la Réception ou en Maternité ne se retire pas d'ici : elle
 * appartient au parcours qui l'a faite (ADR-159, ADR-067).
 */
class CancelSurgeryFromStayAction
{
    public const DEFAULT_REASON = 'Transfert au bloc annulé depuis le séjour.';

    public function __construct(
        private readonly WithdrawSurgicalRequestAction $withdraw,
        private readonly Auditor $auditor,
    ) {}

    public function execute(HospitalStay $stay, SurgicalRequest $request, ?string $reason, User $actor): SurgicalRequest
    {
        return DB::transaction(function () use ($stay, $request, $reason, $actor): SurgicalRequest {
            $episode = Episode::query()->lockForUpdate()->findOrFail($stay->episode_id);
            $locked = SurgicalRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->episode_id !== $episode->getKey()) {
                throw ValidationException::withMessages(['surgical_request' => 'Cette demande n’appartient pas à ce passage.']);
            }

            if ($episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['surgical_request' => 'Ce passage est clos.']);
            }

            if ($locked->status === SurgicalRequestStatus::Cancelled) {
                throw ValidationException::withMessages(['surgical_request' => 'Cette demande a déjà été annulée.']);
            }

            if (! self::cancellableOrigin($locked)) {
                throw ValidationException::withMessages([
                    'surgical_request' => 'Cette demande a été faite depuis la Réception ou la Maternité : elle se retire là où elle a été faite.',
                ]);
            }

            $reason = filled($reason) ? trim($reason) : self::DEFAULT_REASON;
            $before = ['status' => $locked->status->value];

            $this->withdraw->execute($locked, $reason, $actor);
            $this->cancelConsultationOrientation($locked, $reason, $actor);

            $this->auditor->record(
                'hospitalization.surgery.cancel',
                entity: $locked,
                oldValues: $before,
                newValues: ['status' => SurgicalRequestStatus::Cancelled->value, 'reason' => $reason, 'hospital_stay' => $stay->uuid],
            );

            EpisodeSettlement::advanceWhenNoServiceLeft($episode->fresh());

            return $locked->fresh();
        });
    }

    /** Le séjour ou une consultation du passage : les deux chemins qui partent d'un patient au lit. */
    public static function cancellableOrigin(SurgicalRequest $request): bool
    {
        return in_array($request->origin, [SurgicalRequestOrigin::Hospitalization, SurgicalRequestOrigin::Medicine], true);
    }

    /**
     * La conduite à tenir « Chirurgie » d'une consultation suit sa demande.
     *
     * Une consultation encore ouverte perd aussi sa décision : le médecin devra
     * en choisir une autre pour clôturer (ADR-084). Une consultation close n'est
     * pas réécrite (ADR-076) — seul le sort de sa demande est consigné.
     */
    private function cancelConsultationOrientation(SurgicalRequest $request, string $reason, User $actor): void
    {
        $orientation = ConsultationOrientation::query()
            ->where('surgical_request_id', $request->getKey())
            ->where('status', '!=', ConsultationOrientationStatus::Cancelled->value)
            ->with('consultation')
            ->lockForUpdate()
            ->first();

        if (! $orientation) {
            return;
        }

        $orientation->update([
            'status' => ConsultationOrientationStatus::Cancelled,
            'cancelled_by' => $actor->getKey(),
            'cancelled_at' => now(),
            'cancellation_reason' => mb_substr($reason, 0, 500),
            'active_key' => null,
        ]);

        if ($orientation->consultation?->isEditable()) {
            $orientation->consultation->update(['decision' => null]);
        }
    }
}
