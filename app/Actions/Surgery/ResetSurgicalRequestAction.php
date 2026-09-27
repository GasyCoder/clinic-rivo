<?php

namespace App\Actions\Surgery;

use App\Actions\Care\CancelCareConsumableRequestAction;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\EpisodeStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\CareConsumableRequest;
use App\Models\SurgicalRequest;
use App\Models\SurgicalRequestReset;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-171 — remet à zéro un dossier du bloc saisi à tort.
 *
 * Tout ce que le bloc et l'anesthésie ont écrit sur ce dossier est d'abord
 * figé dans une archive (`surgical_request_resets`) avec le motif, l'auteur et
 * l'heure, puis retiré ; la demande elle-même (intervention demandée, origine,
 * demandeur) est gardée et repasse « À programmer ». Rien n'est perdu : c'est
 * un archivage (ADR-010), pas une suppression muette.
 *
 * Ce qui a déjà quitté le dossier n'est pas défait ici :
 *   matériel déjà servi par la Pharmacie  → refus : le stock a bougé, la
 *                                            correction est un ajustement audité
 *   matériel demandé, pas encore servi    → la demande est annulée (même
 *                                            règle que son annulation, ADR-169)
 */
class ResetSurgicalRequestAction
{
    public function __construct(
        private readonly CancelCareConsumableRequestAction $cancelConsumables,
        private readonly Auditor $auditor,
    ) {}

    public function execute(SurgicalRequest $surgicalRequest, string $reason, User $actor): SurgicalRequest
    {
        if (! $actor->can('surgery.reset')) {
            throw new AuthorizationException('Réinitialiser un dossier du bloc demande le droit « surgery.reset ».');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Le motif de la réinitialisation est obligatoire.']);
        }

        return DB::transaction(function () use ($surgicalRequest, $reason, $actor): SurgicalRequest {
            $case = SurgicalRequest::query()->with('episode')->lockForUpdate()->findOrFail($surgicalRequest->getKey());

            if ($case->status === SurgicalRequestStatus::Cancelled) {
                throw ValidationException::withMessages(['reset' => 'Cette demande est annulée : il n’y a rien à réinitialiser.']);
            }

            if ($case->episode?->status === EpisodeStatus::Closed) {
                throw ValidationException::withMessages(['reset' => 'Le passage est clos par la sortie administrative : le dossier du bloc ne se réinitialise plus.']);
            }

            $consumableRequests = CareConsumableRequest::query()
                ->where('surgical_request_id', $case->getKey())
                ->lockForUpdate()
                ->get();

            $served = $consumableRequests->first(fn (CareConsumableRequest $request) => in_array(
                $request->status,
                [CareConsumableRequestStatus::Served, CareConsumableRequestStatus::PartiallyServed],
                true,
            ));
            if ($served) {
                throw ValidationException::withMessages([
                    'reset' => "Du matériel de ce dossier a déjà été servi par la Pharmacie ({$served->request_number}) : le stock a bougé. Faites-le corriger par un ajustement de stock, puis réinitialisez.",
                ]);
            }

            $case->load([
                'intervention', 'report', 'anesthesiaRecord.clearanceConditions', 'complications',
                'consumables', 'teamMembers', 'careNotes', 'safetyChecklists.confirmations',
                'blockEntry', 'blockExit', 'observations', 'treatmentItems',
            ]);

            $previousStatus = $case->status->value;
            $snapshot = $this->snapshot($case);

            if (! $this->hasAnythingToReset($case, $snapshot)) {
                throw ValidationException::withMessages(['reset' => 'Ce dossier ne porte encore aucune saisie du bloc : il n’y a rien à réinitialiser.']);
            }

            foreach ($consumableRequests as $request) {
                if ($request->status === CareConsumableRequestStatus::Pending) {
                    $this->cancelConsumables->execute($request, "Réinitialisation du dossier du bloc : {$reason}", $actor);
                }
            }

            $this->purge($case);

            $case->forceFill([
                'status' => SurgicalRequestStatus::Pending,
                'surgeon_id' => null,
                'scheduled_at' => null,
                'operating_room' => null,
                'preparation_notes' => null,
                'preoperative_notes' => null,
                'preoperative_assessed_by' => null,
                'preoperative_assessed_at' => null,
                'preoperative_validated_by' => null,
                'preoperative_validated_at' => null,
                'completed_at' => null,
                'discharged_by' => null,
                'discharged_at' => null,
                'discharge_notes' => null,
            ])->save();

            $archive = SurgicalRequestReset::create([
                'surgical_request_id' => $case->getKey(),
                'previous_status' => $previousStatus,
                'snapshot' => $snapshot,
                'reason' => $reason,
                'reset_by' => $actor->getKey(),
                'reset_at' => now(),
            ]);

            $this->auditor->record(
                'surgery.request.reset',
                entity: $case,
                oldValues: ['status' => $previousStatus, 'archived' => $this->counts($snapshot)],
                newValues: ['status' => SurgicalRequestStatus::Pending->value, 'archive_uuid' => $archive->uuid],
                reason: $reason,
                module: 'surgery',
                actor: $actor,
            );

            return $case->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(SurgicalRequest $case): array
    {
        return [
            'request' => $case->only([
                'status', 'surgeon_id', 'scheduled_at', 'operating_room', 'preparation_notes',
                'preoperative_notes', 'preoperative_assessed_by', 'preoperative_assessed_at',
                'preoperative_validated_by', 'preoperative_validated_at', 'completed_at',
                'discharged_by', 'discharged_at', 'discharge_notes',
            ]),
            'intervention' => $case->intervention?->getAttributes(),
            'report' => $case->report?->getAttributes(),
            'anesthesia_record' => $case->anesthesiaRecord?->getAttributes(),
            'anesthesia_clearance_conditions' => $case->anesthesiaRecord?->clearanceConditions->map->getAttributes()->all() ?? [],
            'team_members' => $case->teamMembers->map->getAttributes()->all(),
            'block_entry' => $case->blockEntry?->getAttributes(),
            'block_exit' => $case->blockExit?->getAttributes(),
            'safety_checklists' => $case->safetyChecklists->map(fn ($checklist) => [
                ...$checklist->getAttributes(),
                'confirmations' => $checklist->confirmations->map->getAttributes()->all(),
            ])->all(),
            'complications' => $case->complications->map->getAttributes()->all(),
            'consumables' => $case->consumables->map->getAttributes()->all(),
            'care_notes' => $case->careNotes->map->getAttributes()->all(),
            'observations' => $case->observations->map->getAttributes()->all(),
            'treatment_items' => $case->treatmentItems->map->getAttributes()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function hasAnythingToReset(SurgicalRequest $case, array $snapshot): bool
    {
        if ($case->status !== SurgicalRequestStatus::Pending) {
            return true;
        }

        return collect($snapshot)->except('request')->contains(fn ($value) => ! empty($value))
            || collect($snapshot['request'])->except('status')->contains(fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, int>
     */
    private function counts(array $snapshot): array
    {
        return collect($snapshot)->except('request')->map(fn ($value) => match (true) {
            is_array($value) && array_is_list($value) => count($value),
            $value === null => 0,
            default => 1,
        })->filter()->all();
    }

    private function purge(SurgicalRequest $case): void
    {
        foreach ($case->safetyChecklists as $checklist) {
            $checklist->confirmations()->delete();
        }
        $case->safetyChecklists()->delete();

        if ($case->anesthesiaRecord) {
            $case->anesthesiaRecord->clearanceConditions()->delete();
            $case->anesthesiaRecord()->delete();
        }

        $case->report()->delete();
        $case->intervention()->delete();
        $case->complications()->delete();
        $case->consumables()->delete();
        $case->teamMembers()->delete();
        $case->careNotes()->delete();
        $case->observations()->getQuery()->delete();
        $case->treatmentItems()->getQuery()->delete();
        $case->blockEntry()->delete();
        $case->blockExit()->delete();
    }
}
