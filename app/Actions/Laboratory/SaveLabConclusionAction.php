<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-214 — la conclusion générale d'une demande, écrite par le biologiste et
 * imprimée sous tous les résultats (la « conclusion générale » du laboratoire de
 * la clinique). Elle se corrige tant qu'une analyse de la demande n'est pas
 * validée ; ensuite la feuille est signée et ne change plus.
 */
class SaveLabConclusionAction
{
    public function execute(LabRequest $request, ?string $conclusion, User $actor): LabRequest
    {
        if ($actor->cannot('laboratory_results.validate')) {
            throw new AuthorizationException('La conclusion générale demande le droit « laboratory_results.validate ».');
        }

        return DB::transaction(function () use ($request, $conclusion, $actor): LabRequest {
            $locked = LabRequest::query()->lockForUpdate()->with('items')->findOrFail($request->getKey());

            if ($locked->cancelled_at !== null) {
                throw ValidationException::withMessages(['conclusion' => 'Cette demande a été retirée par le prescripteur.']);
            }
            if ($locked->items->isNotEmpty() && $locked->items->every(fn ($item) => $item->currentStatus() === LabItemStatus::Validated)) {
                throw ValidationException::withMessages(['conclusion' => 'Toutes les analyses sont validées : la feuille est signée, la conclusion ne se modifie plus.']);
            }

            $text = filled($conclusion) ? mb_substr(trim($conclusion), 0, 3000) : null;
            $locked->update([
                'conclusion' => $text,
                'conclusion_at' => $text !== null ? now() : null,
                'conclusion_by' => $text !== null ? $actor->getKey() : null,
            ]);

            return $locked->fresh();
        });
    }
}
