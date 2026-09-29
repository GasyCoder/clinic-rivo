<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequestItem;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — les conditions communes aux gestes de la paillasse, relues sur une
 * ligne verrouillée : une demande retirée par le médecin ne se travaille plus
 * (ADR-079), et une analyse terminée ou validée ne se modifie plus.
 *
 * ADR-214 — et rien ne se saisit avant la réception de la demande : c'est elle
 * qui contrôle le règlement (CDC §14 : « Payé ? Non → En attente »).
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
                LabItemStatus::Validated => 'Cette analyse est envoyée au médecin : elle ne se modifie plus. Renvoyez-la à refaire, avec un motif.',
                default => 'Ce résultat est rendu et attend d’être envoyé. Reprenez-le d’abord pour le modifier.',
            }]);
        }

        return $locked;
    }

    /** Saisissable, et la demande est passée par la réception du laboratoire. */
    public static function lockWorkable(LabRequestItem $item): LabRequestItem
    {
        $locked = self::lockEditable($item);
        self::ensureReceived($locked);

        return $locked;
    }

    public static function ensureReceived(LabRequestItem $item, string $key = 'item'): void
    {
        if ($item->labRequest->received_at === null) {
            throw ValidationException::withMessages([$key => 'Réceptionnez d’abord la demande : c’est la réception qui contrôle le règlement et enregistre les prélèvements.']);
        }
    }
}
