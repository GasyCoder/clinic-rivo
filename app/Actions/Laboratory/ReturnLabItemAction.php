<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Notifications\LabRedoRequested;
use App\Notifications\LabResultReturned;
use App\Services\Laboratory\LabResultAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 / ADR-216 — renvoyer une analyse à refaire, avec un motif.
 *
 * Une analyse rendue ou déjà envoyée au médecin se reprend avec un seul droit,
 * `laboratory_results.return`, que le Super Administrateur accorde ou retire
 * depuis « Rôles & permissions » (amendement du 2026-09-29).
 *
 * Le résultat rendu reste lisible jusqu'au prochain envoi — il a pu être lu, et
 * le retirer ferait croire qu'il n'a jamais existé. L'état « À refaire » le dit
 * partout, et le médecin destinataire en est prévenu. L'audit garde l'ancien état.
 */
class ReturnLabItemAction
{
    public const PERMISSION = 'laboratory_results.return';

    public function execute(LabRequestItem $item, string $reason, User $actor): LabRequestItem
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Indiquez pourquoi l’analyse est à refaire.']);
        }

        [$returned, $wasSent, $sender] = DB::transaction(function () use ($item, $reason, $actor): array {
            $locked = LabItemGuard::lock($item);
            $status = $locked->currentStatus();

            if (! in_array($status, [LabItemStatus::Completed, LabItemStatus::Validated], true)) {
                throw ValidationException::withMessages(['reason' => 'Seule une analyse rendue ou envoyée se renvoie à refaire.']);
            }
            // ADR-216, amendement du 2026-09-29 — un droit propre, réglé depuis le
            // portail : il ne se déduit plus de la saisie ni de l'envoi.
            if (! $actor->can(self::PERMISSION)) {
                throw new AuthorizationException('Renvoyer une analyse à refaire demande le droit « '.self::PERMISSION.' ».');
            }
            // Un résultat adressé à un confrère ne se reprend pas sans l'avoir ouvert (ADR-216).
            if (app(LabResultAccess::class)->sealed($locked->labRequest, $actor)) {
                throw new AuthorizationException('Ce résultat est adressé à un confrère : ouvrez-le d’abord.');
            }
            $sender = $locked->validated_by;

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

            return [$locked->fresh(), $status === LabItemStatus::Validated, $sender];
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

        // Amendement du 2026-09-29 — un médecin peut aussi demander qu'un résultat
        // soit refait : le technicien qui l'avait envoyé est prévenu.
        $senderUser = $wasSent && $sender !== null && (int) $sender !== (int) $actor->getKey() ? User::query()->find($sender) : null;
        if ($senderUser !== null && $request !== null) {
            $senderUser->notify(new LabRedoRequested(
                request: $request,
                analysis: $returned->catalog_item_name_snapshot,
                reason: mb_substr($reason, 0, 200),
                by: $actor->name,
            ));
        }

        return $returned;
    }
}
