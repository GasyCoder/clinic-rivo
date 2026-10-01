<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequest;
use Illuminate\Validation\ValidationException;

/**
 * ADR-220 — la demande que l'on range, corrige ou met à la corbeille, relue sous verrou.
 *
 * Une demande retirée par le prescripteur (ADR-079) n'appartient plus à la file :
 * aucun de ces gestes ne s'y applique.
 */
final class LabRequestGuard
{
    public static function lock(LabRequest $request): LabRequest
    {
        /** @var LabRequest $locked */
        $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());
        $locked->load('items');

        if ($locked->isCancelled()) {
            throw ValidationException::withMessages([
                'request' => 'Cette demande a été retirée par le prescripteur : elle ne se modifie plus.',
            ]);
        }

        return $locked;
    }

    /** Une analyse de la demande a-t-elle déjà été envoyée au médecin ? Il l'a peut-être lue. */
    public static function anySent(LabRequest $request): bool
    {
        return $request->items->contains(fn ($item) => $item->sent_at !== null);
    }

    /** Tout est envoyé : la demande est terminée, elle peut se ranger. */
    public static function finished(LabRequest $request): bool
    {
        return $request->items->isNotEmpty()
            && $request->items->every(fn ($item) => $item->sent_at !== null && $item->currentStatus()->value === 'VALIDATED');
    }
}
