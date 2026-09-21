<?php

namespace App\Actions\Surgery;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\EpisodeOrientation;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Retirer une demande au bloc que le bloc n'a pas encore programmée (ADR-084,
 * ADR-163).
 *
 * Une seule règle, partagée par la consultation qui change de conduite à tenir
 * et par le séjour qui annule un « Transférer au bloc » : tant que la demande
 * est « À programmer », elle appartient encore à qui l'a faite ; dès que le bloc
 * a programmé, elle lui appartient, et le retrait est refusé plutôt que de
 * défaire en silence le travail d'un autre service (choix du propriétaire,
 * 2026-09-21).
 *
 * Rien n'est supprimé (ADR-010) : la demande garde son auteur, sa date et son
 * numéro, et porte désormais qui l'a retirée, quand, et pourquoi.
 *
 * L'orientation du passage vers le bloc est commune à toutes ses demandes
 * (`active_key` = passage + destination). Elle n'est annulée que si plus
 * aucune demande ne l'utilise : retirer une intervention n'en retire pas une
 * autre, demandée par un autre chemin.
 *
 * À appeler dans la transaction de l'appelant.
 */
class WithdrawSurgicalRequestAction
{
    public function execute(SurgicalRequest $request, string $reason, User $actor): SurgicalRequest
    {
        /** @var SurgicalRequest $locked */
        $locked = SurgicalRequest::query()->lockForUpdate()->findOrFail($request->getKey());

        if ($locked->status === SurgicalRequestStatus::Cancelled) {
            return $locked;
        }

        if ($locked->status !== SurgicalRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'surgical_request' => 'Le bloc a déjà programmé cette intervention : elle lui appartient désormais. Voyez avec l’équipe du bloc pour la déprogrammer.',
            ]);
        }

        $locked->update([
            'status' => SurgicalRequestStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->getKey(),
            'cancellation_reason' => mb_substr(trim($reason), 0, 500),
        ]);

        $stillUsed = SurgicalRequest::query()
            ->where('episode_id', $locked->episode_id)
            ->whereKeyNot($locked->getKey())
            ->where('status', '!=', SurgicalRequestStatus::Cancelled->value)
            ->exists();

        if (! $stillUsed) {
            EpisodeOrientation::query()
                ->where('episode_id', $locked->episode_id)
                ->where('destination_module', CatalogModule::Surgery->value)
                ->where('status', EpisodeOrientationStatus::Pending->value)
                ->lockForUpdate()
                ->get()
                ->each(fn (EpisodeOrientation $orientation) => $orientation->cancel($actor));
        }

        return $locked->fresh();
    }
}
