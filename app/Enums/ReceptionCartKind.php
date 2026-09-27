<?php

namespace App\Enums;

/**
 * ADR-104 — le panier de la Réception porte deux rayons.
 *
 * La distinction n'est pas cosmétique : les deux lignes ne passent pas par
 * le même circuit. Une prestation ouvre une file clinique et rejoint la
 * facture du passage ; un médicament réserve du stock en FEFO et rejoint un
 * ticket Pharmacie distinct, dont la délivrance attend le règlement
 * (ADR-049, ADR-050). Les confondre ferait délivrer un médicament sur une
 * facture partiellement payée.
 */
enum ReceptionCartKind: string
{
    case Service = 'SERVICE';
    case Medicine = 'MEDICINE';

    /**
     * Une ligne sans `kind` est une prestation.
     *
     * Les brouillons enregistrés avant l'ADR-104 n'en portent pas, et ils
     * ne contenaient que des prestations : le déduire est une lecture de
     * ce qui existait, jamais une invention.
     */
    public static function fromLine(array $line): self
    {
        $raw = $line['kind'] ?? null;

        return $raw === self::Medicine->value ? self::Medicine : self::Service;
    }

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Prestation',
            self::Medicine => 'Médicament',
        };
    }
}
