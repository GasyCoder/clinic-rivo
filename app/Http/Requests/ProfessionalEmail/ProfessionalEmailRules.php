<?php

namespace App\Http\Requests\ProfessionalEmail;

use App\Support\ProfessionalEmailAddress;

/**
 * ADR-190 — les règles de saisie des adresses professionnelles, les mêmes sur
 * le portail et sur le site : `$request->validate(...ProfessionalEmailRules::localPart())`.
 */
final class ProfessionalEmailRules
{
    /** @return array{0: array<string, array<int, string>>, 1: array<string, string>} */
    public static function localPart(): array
    {
        return [
            ['local_part' => ['required', 'string', 'max:64', 'regex:'.ProfessionalEmailAddress::LOCAL_PART_PATTERN]],
            ['local_part.regex' => ProfessionalEmailAddress::LOCAL_PART_MESSAGE, 'local_part.required' => 'Indiquez la partie de l’adresse avant « @ ».'],
        ];
    }

    /** @return array{0: array<string, array<int, string>>, 1: array<string, string>} */
    public static function direct(): array
    {
        [$rules, $messages] = self::localPart();

        return [['employee_uuid' => ['required', 'uuid'], ...$rules], $messages];
    }

    /** @return array<string, array<int, string>> */
    public static function reason(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
