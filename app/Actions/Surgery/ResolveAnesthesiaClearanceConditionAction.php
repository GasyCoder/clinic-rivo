<?php

namespace App\Actions\Surgery;

use App\Enums\AnesthesiaClearanceConditionStatus;
use App\Models\AnesthesiaClearanceCondition;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — lever une réserve posée par une autorisation sous conditions.
 *
 * C'est l'anesthésie qui lève ce que l'anesthésie a posé : laisser le bloc
 * cocher lui-même les réserves de l'anesthésiste reviendrait à lui rendre
 * l'autorisation qu'on venait de lui retirer. Une réserve levée n'est jamais
 * rouverte ici — c'est un constat, et une nouvelle décision en pose de
 * nouvelles si l'état du patient a changé.
 */
class ResolveAnesthesiaClearanceConditionAction
{
    public function __construct(private readonly SurgicalCaseActors $actors) {}

    public function execute(AnesthesiaClearanceCondition $condition, ?string $notes, User $actor): AnesthesiaClearanceCondition
    {
        return DB::transaction(function () use ($condition, $notes, $actor): AnesthesiaClearanceCondition {
            $locked = AnesthesiaClearanceCondition::query()
                ->with('anesthesiaRecord.surgicalRequest.teamMembers')
                ->lockForUpdate()
                ->findOrFail($condition->getKey());

            $case = $locked->anesthesiaRecord->surgicalRequest;

            if (! $this->actors->canWriteAnesthesia($case, $actor)) {
                throw ValidationException::withMessages([
                    'condition' => 'Une condition posée par l’anesthésiste se lève par l’anesthésiste.',
                ]);
            }

            if (! $locked->isOpen()) {
                // Idempotent : un double clic ne réécrit pas qui a levé la réserve.
                return $locked;
            }

            $locked->status = AnesthesiaClearanceConditionStatus::Resolved;
            $locked->resolved_by = $actor->getKey();
            $locked->resolved_at = now();
            $locked->resolution_notes = filled($notes) ? trim((string) $notes) : null;
            $locked->save();

            return $locked;
        });
    }
}
