<?php

namespace App\Enums;

/**
 * ADR-211 — les deux sortes de partenaires de la clinique.
 *
 * Médical : une personne du monde de la santé (médecin, infirmier,
 * laborantin…), connue par son nom, son prénom et son métier. Elle peut venir
 * elle-même en patient : l'accueil reprend alors sa fiche sans ressaisie.
 *
 * Autre : un organisme ou une personne (une école comme l'ISPSG, une
 * entreprise), connu par un nom ou une identité. Il n'est jamais le patient :
 * on le choisit à la prise en charge d'un passage.
 */
enum PartnerCategory: string
{
    case Medical = 'MEDICAL';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Medical => 'Médical',
            self::Other => 'Autre',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $category) => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
