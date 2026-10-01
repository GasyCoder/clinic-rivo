<?php

namespace App\Support\Hr;

/**
 * Les enfants déclarés d'un employé : une liste (prénom, F/G, âge) dont la longueur
 * est le nombre d'enfants — les deux ne peuvent donc pas se contredire.
 */
final class EmployeeChildren
{
    /**
     * Ne garde que les lignes renseignées, dans la forme {name, sex, age}.
     *
     * @param  mixed  $rows
     * @return array<int, array{name: ?string, sex: ?string, age: ?int}>
     */
    public static function normalize(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = str((string) ($row['name'] ?? ''))->squish()->toString();
            $sex = strtoupper(trim((string) ($row['sex'] ?? '')));
            $age = $row['age'] ?? null;
            $age = ($age === null || $age === '') ? null : (int) $age;

            if ($name === '' && $sex === '' && $age === null) {
                continue;
            }

            $clean[] = ['name' => $name === '' ? null : $name, 'sex' => $sex === '' ? null : $sex, 'age' => $age];
        }

        return $clean;
    }

    /**
     * Relit l'ancienne note libre « Mayrah(F, 3ans) Malyah(F, 3ans) » ; renvoie
     * null si elle ne se lit pas entièrement (rien n'est alors deviné).
     *
     * @return array<int, array{name: ?string, sex: ?string, age: ?int}>|null
     */
    public static function parse(?string $text): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $pattern = '/([^\(\),;\n]+?)\s*\(\s*([FGfg])\s*[,;]?\s*(\d{1,2})\s*ans?\s*\)/u';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $rest = trim(preg_replace($pattern, '', $text), " \t\n\r,;.");

        if ($rest !== '') {
            return null;
        }

        return self::normalize(array_map(fn (array $m) => [
            'name' => $m[1], 'sex' => strtoupper($m[2]), 'age' => (int) $m[3],
        ], $matches));
    }

    /** Une ligne lisible, pour l'export et la fiche imprimée. */
    public static function label(mixed $rows): string
    {
        return collect(self::normalize($rows))->map(function (array $child): string {
            $detail = collect([$child['sex'], $child['age'] !== null ? $child['age'].' ans' : null])->filter()->implode(', ');

            return trim(($child['name'] ?? 'Enfant').($detail !== '' ? " ({$detail})" : ''));
        })->implode(' ; ');
    }
}
