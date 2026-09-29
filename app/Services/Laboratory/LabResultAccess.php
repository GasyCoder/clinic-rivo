<?php

namespace App\Services\Laboratory;

use App\Models\LabRequest;
use App\Models\User;
use App\Services\Audit\Auditor;

/**
 * ADR-216 — un résultat d'analyse adressé à un médecin se lit librement par
 * lui ; les autres le voient exister et l'ouvrent après confirmation.
 *
 * Se lisent sans confirmation :
 *
 *   - une demande adressée à personne (patient externe, ou envoyée avant
 *     l'ADR-216) ;
 *   - chacun des médecins destinataires, et le prescripteur de la demande ;
 *   - le laboratoire lui-même — qui saisit (`laboratory_results.create`) : c'est
 *     lui qui a produit le résultat. `laboratory_results.view` ne suffit pas,
 *     le socle Médecine le détient aussi.
 *
 * Pour les autres, le serveur ne sert pas les valeurs (`sealed()`) tant que la
 * personne n'a pas confirmé ; l'ouverture est tracée à l'audit et vaut pour la
 * session. La fenêtre de confirmation n'est jamais la seule garde.
 */
final class LabResultAccess
{
    public const SESSION_KEY = 'lab_results.opened';

    public function __construct(private readonly Auditor $auditor) {}

    public function sealed(LabRequest $request, ?User $viewer): bool
    {
        // Amendement ADR-216 du 2026-09-29 (ter) — adressés à un, plusieurs ou tous : chacun
        // des destinataires les lit librement.
        if ($viewer === null || $request->recipientIds() === []) {
            return false;
        }

        $id = $viewer->getKey();

        if ($id !== null && ($request->isAddressedTo($viewer) || (int) $id === (int) $request->requested_by)) {
            return false;
        }

        if ($viewer->can('laboratory_results.create')) {
            return false;
        }

        return ! in_array($request->uuid, $this->opened(), true);
    }

    /**
     * Ce que l'écran dit d'un résultat scellé : à qui il est adressé, et
     * l'adresse qui l'ouvre.
     *
     * @return array{recipient: ?string, addressed_at: ?string, open_url: string}
     */
    public function seal(LabRequest $request): array
    {
        return [
            'recipient' => $request->recipientNames(),
            'addressed_at' => $request->results_addressed_at?->toIso8601String(),
            'open_url' => "/resultats-analyses/{$request->uuid}/ouvrir",
        ];
    }

    /** Le sceau d'une demande pour ce lecteur, ou `null` s'il la lit librement. */
    public function sealFor(LabRequest $request, ?User $viewer): ?array
    {
        return $this->sealed($request, $viewer) ? $this->seal($request) : null;
    }

    /** Ouvrir un résultat adressé à un autre : tracé une fois, valable pour la session. */
    public function open(LabRequest $request, User $viewer): void
    {
        if (! $this->sealed($request, $viewer)) {
            return;
        }

        $this->auditor->record(
            'laboratory.results.open',
            $request,
            [
                'recipient' => $request->recipientNames(),
                'lab_number' => $request->lab_number,
            ],
            module: 'laboratory',
            actor: $viewer,
        );

        if (request()->hasSession()) {
            request()->session()->put(self::SESSION_KEY, array_values(array_unique([...$this->opened(), $request->uuid])));
        }
    }

    /** @return list<string> */
    private function opened(): array
    {
        return request()->hasSession() ? (array) request()->session()->get(self::SESSION_KEY, []) : [];
    }
}
