<?php

namespace App\Enums;

use Illuminate\Routing\Route;

/**
 * ADR-188 — les deux modules de structure RH : Départements et Fonctions.
 *
 * Chacun gère un type du référentiel RH (`hr_reference_values`) déjà utilisé
 * par les dossiers Employé, le planning et l'import. Le type se lit sur
 * l'adresse, jamais sur une valeur envoyée : un formulaire de fonctions ne
 * peut pas écrire un département.
 */
enum HrStructureKind: string
{
    case Departments = 'departments';
    case JobTitles = 'job-titles';

    public function referenceType(): HrReferenceType
    {
        return match ($this) {
            self::Departments => HrReferenceType::Department,
            self::JobTitles => HrReferenceType::JobTitle,
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Departments => 'Département',
            self::JobTitles => 'Fonction',
        };
    }

    /** L'adresse du module, qui se lit identiquement au site et sur l'API du portail. */
    public static function fromRoute(?Route $route): self
    {
        $segments = explode('/', (string) $route?->uri());

        return in_array(self::JobTitles->value, $segments, true) ? self::JobTitles : self::Departments;
    }
}
