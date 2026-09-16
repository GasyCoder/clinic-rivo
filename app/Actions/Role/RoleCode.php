<?php

namespace App\Actions\Role;

use Illuminate\Validation\ValidationException;

/**
 * Le code d'un rôle est son identité, d'un site à l'autre et dans le temps.
 *
 * `RolePermissionSeeder::GRANTS`, les exceptions individuelles et l'audit le
 * désignent par ce code ; il est donc normalisé une seule fois et n'est plus
 * modifiable ensuite — renommer « MEDICINE » en « MED » ferait pointer un
 * socle codé en dur vers un rôle qui n'existe plus. Le libellé, lui, se
 * corrige librement.
 */
final class RoleCode
{
    public static function normalize(string $code): string
    {
        $normalized = mb_strtoupper(trim($code));

        if (! preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $normalized)) {
            throw ValidationException::withMessages([
                'code' => 'Le code d’un rôle s’écrit en majuscules, sans accent ni espace : lettres, chiffres et « _ » (ex. KINESITHERAPEUTE).',
            ]);
        }

        return $normalized;
    }
}
