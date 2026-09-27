<?php

namespace App\Actions\Permission;

use Illuminate\Validation\ValidationException;

/**
 * `resource.action` — la convention de l'ADR-007, appliquée à la saisie.
 *
 * Le nom est ce que le code écrit en clair (`can:patients.view`). Il ne se
 * corrige donc jamais après coup : une route, une Policy ou un écran
 * pointerait vers un nom qui n'existe plus, et le contrôle passerait
 * silencieusement à « personne n'a ce droit ». Seul le libellé se corrige.
 */
final class PermissionName
{
    public static function normalize(string $name): string
    {
        $normalized = mb_strtolower(trim($name));

        if (! preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $normalized)) {
            throw ValidationException::withMessages([
                'name' => 'Un nom de permission s’écrit « ressource.action », en minuscules et sans accent — par exemple kinesitherapie.view.',
            ]);
        }

        if (mb_strlen($normalized) > 100) {
            throw ValidationException::withMessages([
                'name' => 'Un nom de permission ne dépasse pas 100 caractères.',
            ]);
        }

        return $normalized;
    }
}
