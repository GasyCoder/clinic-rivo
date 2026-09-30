<?php

namespace App\Enums;

/** Ce qu'un article d'avantage compte : les actes que la personne a faits, ou les patients qu'elle a envoyés. */
enum AdvantageSource: string
{
    case Performed = 'PERFORMED';
    case Referred = 'REFERRED';

    public function label(): string
    {
        return match ($this) {
            self::Performed => 'Actes réalisés',
            self::Referred => 'Patients référés',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Performed => 'Chaque acte de l’article fait par la personne (compte rendu d’échographie ou d’ECG, résultat d’analyse, intervention, acte de soins ou de maternité). Seulement le personnel dont le compte est relié à sa fiche.',
            self::Referred => 'Chaque acte de l’article fait sur le passage d’un patient que la personne a recommandé à son arrivée (employé ou partenaire).',
        };
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $source) => ['value' => $source->value, 'label' => $source->label(), 'description' => $source->description()], self::cases());
    }
}
