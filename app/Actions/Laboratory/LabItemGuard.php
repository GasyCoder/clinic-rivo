<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — les conditions communes aux gestes de la paillasse, relues sur une
 * ligne verrouillée : une demande retirée par le médecin ne se travaille plus
 * (ADR-079), et une analyse terminée ou validée ne se modifie plus.
 *
 * ADR-217 — la première saisie prend la demande en charge si personne ne l'a
 * encore fait : le technicien n'est jamais arrêté par une étape de réception,
 * ni par le règlement, qui reste l'affaire de la Caisse.
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
                default => 'Cette analyse est terminée : rouvrez sa saisie (« Autres actions ») pour la modifier.',
            }]);
        }

        return $locked;
    }

    /** Saisissable ; la demande est prise en charge au passage si elle ne l'est pas encore. */
    public static function lockWorkable(LabRequestItem $item, User $actor): LabRequestItem
    {
        $locked = self::lockEditable($item);
        self::ensureTakenUp($locked->labRequest, $actor);

        return $locked;
    }

    public static function ensureTakenUp(LabRequest $request, User $actor): void
    {
        if ($request->received_at !== null) {
            return;
        }

        $lockedRequest = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());
        app(ReceiveLabRequestAction::class)->takeUp($lockedRequest, $actor);
        $request->setRawAttributes($lockedRequest->getAttributes(), true);
    }
}
