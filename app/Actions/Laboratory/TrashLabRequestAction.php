<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequest;
use App\Models\User;
use App\Services\Billing\ParaclinicalBillingRelease;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-220 — mettre à la corbeille une demande d'analyses saisie à tort.
 *
 * Jamais après un envoi au médecin : il a pu lire le résultat, et le retirer
 * de son dossier serait nier un acte rendu (ADR-010, ADR-216). Une saisie en
 * cours ne l'empêche pas : elle n'a été lue par personne.
 *
 * Un motif est exigé. La demande n'est pas détruite (ADR-009) : elle quitte
 * toutes les listes — file, dossiers, parcours —, garde sa trace en base et
 * dans l'audit, et se restaure depuis la Corbeille (ADR-061, ADR-065).
 *
 * Ce que la demande a elle-même porté au compte du patient et qui attend encore
 * d'être facturé est annulé (ADR-105) ; ce qui est déjà sur une facture reste à
 * la Caisse, seule à toucher un montant facturé (ADR-012) — la réponse le compte.
 */
class TrashLabRequestAction
{
    public const PERMISSION = 'laboratory_orders.delete';

    public function __construct(private readonly ParaclinicalBillingRelease $billing) {}

    /** @return array{request: LabRequest, invoiced: int} */
    public function execute(LabRequest $request, ?string $reason, User $actor): array
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Mettre une demande à la corbeille demande le droit « laboratory_orders.delete ».');
        }

        $reason = trim((string) $reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Indiquez pourquoi la demande part à la corbeille.']);
        }

        return DB::transaction(function () use ($request, $reason, $actor): array {
            /** @var LabRequest $locked */
            $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $locked->load('items.billableItem');

            if (LabRequestGuard::anySent($locked)) {
                throw ValidationException::withMessages([
                    'request' => 'Des résultats de cette demande ont déjà été envoyés au médecin : elle ne part pas à la corbeille. Renvoyez l’analyse à refaire pour la corriger.',
                ]);
            }

            $invoiced = 0;
            foreach ($locked->items as $line) {
                if (! $this->billing->releaseLine($line, $actor, 'Demande d’analyses mise à la corbeille — '.$reason)
                    && $line->billableItem !== null
                    && ParaclinicalBillingRelease::isOwnKey($line, $line->billableItem->idempotency_key)
                    && $line->billableItem->status->value !== 'CANCELLED') {
                    $invoiced++;
                }
            }

            $locked->delete_reason = mb_substr($reason, 0, 1000);
            $locked->delete();

            return ['request' => $locked, 'invoiced' => $invoiced];
        });
    }
}
