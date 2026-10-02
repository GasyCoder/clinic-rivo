<?php

namespace App\Enums;

/** ADR-241 — ce qui a été dit d'une paire de produits fournisseurs. */
enum SupplierEquivalenceStatus: string
{
    /** Un humain a dit : c'est le même produit. */
    case Same = 'SAME';

    /** Un humain a dit : ce sont deux produits. */
    case Different = 'DIFFERENT';

    /** L'IA le propose ; personne n'a encore décidé. */
    case Proposed = 'PROPOSED';

    public function label(): string
    {
        return match ($this) {
            self::Same => 'Même produit',
            self::Different => 'Produits différents',
            self::Proposed => 'Proposé par l’IA',
        };
    }
}
