<?php

namespace App\Services\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * ADR-216, amendement quater — ce que la Réception voit des analyses : seulement
 * ce que le médecin a validé. Une analyse envoyée mais pas encore validée, ou
 * reprise par le laboratoire, n'y figure pas — elle n'est pas encore un résultat
 * à remettre au patient.
 *
 * Une seule définition, lue par la liste « Résultats à remettre », son compte
 * rendu PDF et le détail du passage.
 */
class ValidatedLabResults
{
    /** Les analyses validées d'une demande (la contrainte, sur `lab_request_items`). */
    public static function approvedItems(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at')->whereNotNull('sent_at')->where('status', LabItemStatus::Validated->value);
    }

    /** Les demandes qui portent au moins un résultat validé. */
    public static function requests(): Builder
    {
        return LabRequest::query()
            ->whereNull('cancelled_at')
            ->whereHas('items', fn ($items) => self::approvedItems($items));
    }

    /** Une demande dont toutes les analyses sont validées : rien d'autre n'est attendu. */
    public static function complete(Builder $query): Builder
    {
        return $query->whereDoesntHave('items', fn ($items) => $items->where(fn ($pending) => $pending
            ->whereNull('approved_at')->orWhereNull('sent_at')->orWhere('status', '!=', LabItemStatus::Validated->value)));
    }

    /** Ce qui reste attendu, en mots : au laboratoire, ou chez le médecin. */
    public static function pendingLabel(LabRequestItem $item): string
    {
        return match (true) {
            $item->awaitsApproval() => 'À valider par le médecin',
            $item->isDelivered() => 'Reprise par le laboratoire',
            $item->isSentOut() => 'Au laboratoire extérieur',
            default => 'Au laboratoire',
        };
    }
}
