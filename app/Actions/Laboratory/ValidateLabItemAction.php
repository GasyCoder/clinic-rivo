<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213, CDC §14 — le biologiste valide une analyse terminée. Validée, elle
 * ne se modifie plus : la corriger passe par un renvoi motivé et tracé
 * (`ReturnLabItemAction`), jamais par une réécriture (ADR-010).
 */
class ValidateLabItemAction
{
    public function execute(LabRequestItem $item, User $actor): LabRequestItem
    {
        $this->authorize($actor);

        return DB::transaction(fn () => $this->validateLocked(LabItemGuard::lock($item), $actor));
    }

    /** Valider d'un geste toutes les analyses terminées d'une demande. @return int le nombre validé */
    public function executeForRequest(LabRequest $request, User $actor): int
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor): int {
            $items = $request->items()->where('status', LabItemStatus::Completed->value)->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['item' => 'Aucune analyse terminée n’attend la validation dans cette demande.']);
            }

            $items->each(fn (LabRequestItem $item) => $this->validateLocked(LabItemGuard::lock($item), $actor));

            return $items->count();
        });
    }

    private function validateLocked(LabRequestItem $locked, User $actor): LabRequestItem
    {
        if ($locked->currentStatus() !== LabItemStatus::Completed) {
            throw ValidationException::withMessages(['item' => match ($locked->currentStatus()) {
                LabItemStatus::Validated => 'Cette analyse est déjà validée.',
                default => 'Seule une analyse terminée par le technicien se valide.',
            }]);
        }

        $locked->update([
            'status' => LabItemStatus::Validated,
            'validated_at' => now(),
            'validated_by' => $actor->getKey(),
        ]);

        return $locked->fresh();
    }

    private function authorize(User $actor): void
    {
        if ($actor->cannot('laboratory_results.validate')) {
            throw new AuthorizationException('Valider un résultat demande le droit « laboratory_results.validate ».');
        }
    }
}
