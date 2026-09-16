<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Covered = 'COVERED';
    case Cancelled = 'CANCELLED';

    /**
     * Le libellé français d'un statut de facture, servi par le backend.
     *
     * Quatre écrans en gardaient chacun leur copie ; le prochain qui affiche
     * une facture n'a plus à en écrire une cinquième, et un statut ajouté
     * ici cesse de s'afficher en `PARTIALLY_PAID` brut quelque part.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Validated => 'À encaisser',
            self::PartiallyPaid => 'Paiement partiel',
            self::Paid => 'Payée',
            self::Covered => 'Prise en charge',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * Le compte est soldé : encaissé, ou couvert à 100 % par une mutuelle
     * — auquel cas aucun paiement n'existe et il ne doit pas en être
     * fabriqué un (ADR-047).
     */
    public function isSettled(): bool
    {
        return $this === self::Paid || $this === self::Covered;
    }
}
