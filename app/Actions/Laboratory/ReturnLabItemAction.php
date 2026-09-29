<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Notifications\LabResultReturned;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 / ADR-216 — renvoyer une analyse à refaire, avec un motif.
 *
 *   rendue, pas envoyée → à refaire   qui saisit (`laboratory_results.create`) ou
 *                                      qui envoie (`laboratory_results.validate`)
 *   envoyée → à refaire                qui envoie seul : c'est revenir sur un
 *                                      résultat que le médecin a pu lire
 *
 * Le résultat rendu reste lisible jusqu'au prochain envoi — il a pu être lu, et
 * le retirer ferait croire qu'il n'a jamais existé. L'état « À refaire » le dit
 * partout, et le médecin destinataire en est prévenu. L'audit garde l'ancien état.
 */
class ReturnLabItemAction
{
    public function execute(LabRequestItem $item, string $reason, User $actor): LabRequestItem
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Indiquez pourquoi l’analyse est à refaire.']);
        }

        [$returned, $wasSent] = DB::transaction(function () use ($item, $reason, $actor): array {
            $locked = LabItemGuard::lock($item);
            $status = $locked->currentStatus();

            $allowed = match ($status) {
                LabItemStatus::Completed => $actor->can('laboratory_results.validate') || $actor->can('laboratory_results.create'),
                LabItemStatus::Validated => $actor->can('laboratory_results.validate'),
                default => null,
            };

            if ($allowed === null) {
                throw ValidationException::withMessages(['reason' => 'Seule une analyse rendue ou envoyée se renvoie à refaire.']);
            }
            if (! $allowed) {
                throw new AuthorizationException($status === LabItemStatus::Validated
                    ? 'Revenir sur une analyse envoyée au médecin demande le droit « laboratory_results.validate ».'
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

            // ADR-220 — une analyse reprise remet sa demande dans le travail en cours :
            // rangée, elle disparaîtrait de la file avec du travail à faire.
            LabRequest::query()->whereKey($locked->lab_request_id)->whereNotNull('lab_archived_at')
                ->update(['lab_archived_at' => null, 'lab_archived_by' => null]);

            return [$locked->fresh(), $status === LabItemStatus::Validated];
        });

        // ADR-216 — le médecin qui a reçu ce résultat doit savoir qu'il est repris.
        $request = $returned->labRequest()->with(['resultsRecipient', 'episode.patient'])->first();
        $recipient = $request?->resultsRecipient;

        if ($wasSent && $recipient !== null && (int) $recipient->getKey() !== (int) $actor->getKey()) {
            $recipient->notify(new LabResultReturned(
                request: $request,
                analysis: $returned->catalog_item_name_snapshot,
                reason: mb_substr($reason, 0, 200),
                by: $actor->name,
            ));
        }

        return $returned;
    }
}
