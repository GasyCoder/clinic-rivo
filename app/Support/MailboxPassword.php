<?php

namespace App\Support;

/**
 * ADR-190 — le premier mot de passe d'une boîte : fort, montré une seule fois
 * à celui qui la crée, jamais enregistré dans RIVO.
 *
 * Sans caractères ambigus à la lecture (0/O, 1/l/I) : il est souvent recopié
 * à la main depuis une feuille remise à l'employé.
 */
final class MailboxPassword
{
    private const SETS = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnopqrstuvwxyz',
        '23456789',
        '#%+=?@_-',
    ];

    public static function generate(int $length = 16): string
    {
        $length = max(12, $length);
        $all = implode('', self::SETS);

        // Au moins un caractère de chaque famille, puis le reste au hasard.
        $characters = array_map(fn (string $set) => $set[random_int(0, strlen($set) - 1)], self::SETS);
        while (count($characters) < $length) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }

        return implode('', $characters);
    }
}
