<?php

namespace App\Actions\Surgery;

use App\Enums\AnesthesiaClearanceConditionStatus;
use App\Enums\AnesthesiaClearanceStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\AnesthesiaRecord;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — l'anesthésiste prononce sa décision : le bloc peut-il avoir lieu ?
 *
 * C'est un acte distinct de la validation de l'évaluation : une évaluation
 * complète peut conclure « non autorisé ». Rien n'est jamais déduit — tant que
 * personne n'a décidé, le dossier reste `DRAFT` et l'incision est retenue.
 *
 * Une décision est **révisable** tant que l'intervention n'a pas commencé :
 * l'état d'un patient change, et un « non autorisé » du matin peut devenir un
 * « autorisé » l'après-midi. Chaque révision est auditée avec son ancienne et
 * sa nouvelle valeur ; une fois au bloc, la décision ne se réécrit plus.
 */
class DecideAnesthesiaClearanceAction
{
    public function __construct(private readonly SurgicalCaseActors $actors) {}

    /**
     * @param  array{status: string, reason?: ?string, valid_until?: ?string, conditions?: array<int, string>}  $data
     */
    public function execute(AnesthesiaRecord $record, array $data, User $actor): AnesthesiaRecord
    {
        return DB::transaction(function () use ($record, $data, $actor): AnesthesiaRecord {
            $locked = AnesthesiaRecord::query()
                ->with(['surgicalRequest.teamMembers', 'clearanceConditions'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            $case = $locked->surgicalRequest;

            if (! $this->actors->canWriteAnesthesia($case, $actor)) {
                throw ValidationException::withMessages([
                    'clearance' => 'Cette décision appartient à l’anesthésiste affecté au dossier.',
                ]);
            }

            if (in_array($case->status, [
                SurgicalRequestStatus::InProgress,
                SurgicalRequestStatus::Completed,
                SurgicalRequestStatus::Discharged,
            ], true)) {
                throw ValidationException::withMessages([
                    'clearance' => 'L’intervention a commencé : la décision d’autorisation ne se reprononce plus.',
                ]);
            }

            if ($case->status === SurgicalRequestStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'clearance' => 'Cette demande de chirurgie est annulée.',
                ]);
            }

            if (! $locked->assessmentIsValidated()) {
                throw ValidationException::withMessages([
                    'clearance' => 'Validez d’abord l’évaluation pré-anesthésique : la décision se prononce sur un bilan terminé.',
                ]);
            }

            $status = AnesthesiaClearanceStatus::from($data['status']);

            if (! in_array($status, AnesthesiaClearanceStatus::decidable(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Cette décision n’est pas une décision que l’on prononce.',
                ]);
            }

            $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

            if ($status->requiresReason() && $reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Un refus ou un report doit dire pourquoi : l’équipe chirurgicale ne peut pas le deviner.',
                ]);
            }

            $conditions = $this->cleanConditions($data['conditions'] ?? []);

            if ($status === AnesthesiaClearanceStatus::ClearedWithConditions && $conditions === []) {
                throw ValidationException::withMessages([
                    'conditions' => 'Une autorisation sous conditions doit énoncer au moins une condition.',
                ]);
            }

            $locked->clearance_status = $status;
            $locked->clearance_reason = $reason !== '' ? $reason : null;
            $locked->clearance_valid_until = $data['valid_until'] ?? null;
            $locked->clearance_decided_by = $actor->getKey();
            $locked->clearance_decided_at = now();
            $locked->save();

            $this->syncConditions($locked, $status, $conditions, $actor);

            return $locked->load(['clearanceConditions', 'clearanceDecidedBy']);
        });
    }

    /**
     * Les conditions déjà levées ne sont jamais réécrites : ce qui a été
     * constaté résolu le reste (ADR-010). Une nouvelle décision ajoute ce
     * qu'elle pose et retire les réserves encore ouvertes qu'elle ne reprend
     * pas — elles n'ont plus d'objet.
     *
     * @param  array<int, string>  $labels
     */
    private function syncConditions(
        AnesthesiaRecord $record,
        AnesthesiaClearanceStatus $status,
        array $labels,
        User $actor,
    ): void {
        $open = $record->clearanceConditions()
            ->where('status', AnesthesiaClearanceConditionStatus::Open->value)
            ->get();

        foreach ($open as $condition) {
            if (! in_array($condition->label, $labels, true)) {
                $condition->delete();
            }
        }

        if ($status !== AnesthesiaClearanceStatus::ClearedWithConditions) {
            return;
        }

        $existing = $record->clearanceConditions()->pluck('label')->all();

        foreach ($labels as $label) {
            if (in_array($label, $existing, true)) {
                continue;
            }

            $record->clearanceConditions()->create([
                'label' => $label,
                'status' => AnesthesiaClearanceConditionStatus::Open,
                'created_by' => $actor->getKey(),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @return array<int, string>
     */
    private function cleanConditions(array $conditions): array
    {
        return collect($conditions)
            ->map(fn ($label) => trim((string) $label))
            ->filter(fn (string $label) => $label !== '')
            ->unique()
            ->values()
            ->all();
    }
}
