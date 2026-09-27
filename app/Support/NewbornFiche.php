<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * ADR-146 — ce qu'une fiche de nouveau-né du dossier Maternité veut dire.
 *
 * Les fiches sont un tableau JSON (`maternity_records.newborn_data.newborns`) sans table à elles :
 * ce qui les définit — remplie ou non, comment on la nomme — vit donc ici, une seule fois, pour que
 * la Maternité, le dossier médical et la Réception ne l'écrivent pas chacun à leur manière.
 */
final class NewbornFiche
{
    /**
     * Une fiche ouverte d'office pour des jumeaux (ADR-136) n'est pas un nouveau-né consigné : tant qu'aucune
     * case n'est remplie, le bébé n'existe pas — il n'a ni identifiant, ni ligne dans l'arborescence de sa mère.
     *
     * @param  array<string, mixed>  $newborn
     */
    public static function isFilled(array $newborn): bool
    {
        return collect($newborn)->except('uuid')->contains(fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Le nom sous lequel on retrouve le bébé. Un bébé pas encore prénommé se dit par sa mère — jamais un
     * prénom inventé : « Bébé 2 de RAKOTO ».
     *
     * @param  array<string, mixed>  $newborn
     */
    public static function displayName(array $newborn, ?string $motherLastName, int $rank): string
    {
        $last = Str::squish((string) ($newborn['last_name'] ?? ''));
        $first = Str::squish((string) ($newborn['first_name'] ?? ''));

        if ($first !== '') {
            return trim(($last !== '' ? $last : (string) $motherLastName).' '.$first);
        }

        if ($last !== '') {
            return "Bébé {$rank} — {$last}";
        }

        return $motherLastName ? "Bébé {$rank} de {$motherLastName}" : "Bébé {$rank}";
    }
}
