<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequestItem;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — les conditions communes aux gestes de la paillasse, relues sur une
 * ligne verrouillée : une demande retirée par le médecin ne se travaille plus
 * (ADR-079), et une analyse terminée ou validée ne se modifie plus.
 */
final class LabItemGuard
{
    public static function lock(LabRequestItem $item): LabRequestItem
    {
        $locked = LabRequestItem::query()->lockForUpdate()->findOrFail($item->getKey());
        $locked->loadMissing('labRequest');

        if ($locked->labRequest->cancelled_at !== null) {
            throw ValidationException::withMessages(['item' => 'Cette demande a été retirée par le prescripteur : elle ne se travaille plus.']);
        }

        return $locked;
    }

    public static function lockEditable(LabRequestItem $item): LabRequestItem
    {
        $locked = self::lock($item);
        $status = $locked->currentStatus();

        if (! $status->editable()) {
            throw ValidationException::withMessages(['item' => match ($status) {
                LabItemStatus::Validated => 'Cette analyse est validée : elle ne se modifie plus. Le biologiste peut la renvoyer à refaire, avec un motif.',
                default => 'Cette analyse est terminée et attend la validation. Reprenez-la d’abord pour la modifier.',
            }]);
        }

        return $locked;
    }
}
