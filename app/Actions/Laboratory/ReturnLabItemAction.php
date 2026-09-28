<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequestItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — renvoyer une analyse à refaire, avec un motif.
 *
 *   terminée → à refaire   le biologiste (`laboratory_results.validate`), ou le
 *                          technicien qui reprend sa propre saisie avant la
 *                          validation (`laboratory_results.create`)
 *   validée  → à refaire   le biologiste seul : c'est revenir sur une validation
 *
 * Le résultat rendu reste lisible jusqu'à ce que l'analyse soit terminée à
 * nouveau — il a pu être lu, et le retirer ferait croire qu'il n'a jamais
 * existé. L'état « À refaire » le dit partout. L'audit garde l'ancien état.
 */
class ReturnLabItemAction
{
    public function execute(LabRequestItem $item, string $reason, User $actor): LabRequestItem
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Indiquez pourquoi l’analyse est à refaire.']);
        }

        return DB::transaction(function () use ($item, $reason, $actor): LabRequestItem {
            $locked = LabItemGuard::lock($item);
            $status = $locked->currentStatus();

            $allowed = match ($status) {
                LabItemStatus::Completed => $actor->can('laboratory_results.validate') || $actor->can('laboratory_results.create'),
                LabItemStatus::Validated => $actor->can('laboratory_results.validate'),
                default => null,
            };

            if ($allowed === null) {
                throw ValidationException::withMessages(['reason' => 'Seule une analyse terminée ou validée se renvoie à refaire.']);
            }
            if (! $allowed) {
                throw new AuthorizationException($status === LabItemStatus::Validated
                    ? 'Revenir sur une analyse validée demande le droit « laboratory_results.validate ».'
                    : 'Reprendre cette analyse demande le droit « laboratory_results.create ».');
            }

            $locked->update([
                'status' => LabItemStatus::ToRedo,
                'returned_at' => now(),
                'returned_by' => $actor->getKey(),
                'return_reason' => mb_substr($reason, 0, 1000),
                'validated_at' => null,
                'validated_by' => null,
            ]);

            return $locked->fresh();
        });
    }
}
