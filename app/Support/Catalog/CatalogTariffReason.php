<?php

namespace App\Support\Catalog;

use App\Enums\CatalogTariffCategory;

/**
 * ADR-044, amendement du 2026-09-28 (bis) — le motif automatique d'un tarif.
 *
 * Écrit par le serveur, jamais par le navigateur : il dit ce qui s'est passé,
 * pour que l'historique reste lisible sans rien taper. Le site et le portail
 * l'écrivent avec les mêmes mots.
 */
final class CatalogTariffReason
{
    public const INITIAL = 'Tarif initial fixé à la création de la désignation (motif automatique).';

    public static function change(CatalogTariffCategory $category, string $amount): string
    {
        return sprintf(
            'Tarif %s fixé à %s Ar depuis la fiche de la désignation (motif automatique).',
            mb_strtolower($category->label()),
            self::amount($amount),
        );
    }

    /** « 25 000 », « 12 500,50 » : l'écriture d'un montant dans un motif. */
    public static function amount(string $amount): string
    {
        $value = (float) $amount;
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ');
    }
}
