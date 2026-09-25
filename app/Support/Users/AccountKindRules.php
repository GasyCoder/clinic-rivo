<?php

namespace App\Support\Users;

use App\Enums\AccountKind;
use Illuminate\Validation\Rule;

/**
 * ADR-183 — la même règle pour tous les chemins qui créent ou modifient un
 * compte : l'API du site (portail) et l'écran Utilisateurs du site.
 *
 * À la création, le choix est obligatoire : un compte reçoit ce qu'on lui a
 * dit, jamais une valeur par défaut. En modification, omettre le choix laisse
 * le lien tel quel (ADR-074) : un appelant qui ne corrige que le nom ne délie
 * pas la fiche.
 */
final class AccountKindRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $creating): array
    {
        return [
            'account_kind' => [$creating ? 'required' : 'sometimes', Rule::enum(AccountKind::class)],
            'employee_uuid' => [
                'nullable',
                'uuid',
                'required_if:account_kind,'.AccountKind::Staff->value,
                'prohibited_if:account_kind,'.AccountKind::External->value,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'account_kind.required' => 'Indiquez s’il s’agit du personnel de la clinique ou d’une personne externe.',
            'account_kind.enum' => 'Le type de compte doit être « Personnel clinique » ou « Externe ».',
            'employee_uuid.required_if' => 'Choisissez la fiche employé de cette personne.',
            'employee_uuid.prohibited_if' => 'Un compte externe n’est relié à aucune fiche employé.',
        ];
    }
}
