<?php

namespace App\Enums;

/**
 * ADR-211 — le métier d'un partenaire médical.
 *
 * Médecin, infirmier et laborantin viennent du propriétaire ; sage-femme et
 * pharmacien sont les autres métiers de santé déjà présents dans la clinique.
 * « Autre » demande sa précision : la liste ne devine pas un métier.
 */
enum PartnerProfession: string
{
    case Doctor = 'DOCTOR';
    case Nurse = 'NURSE';
    case Midwife = 'MIDWIFE';
    case LabTechnician = 'LAB_TECHNICIAN';
    case Pharmacist = 'PHARMACIST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Doctor => 'Médecin',
            self::Nurse => 'Infirmier·ère',
            self::Midwife => 'Sage-femme',
            self::LabTechnician => 'Laborantin·e',
            self::Pharmacist => 'Pharmacien·ne',
            self::Other => 'Autre métier',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $profession) => ['value' => $profession->value, 'label' => $profession->label()],
            self::cases(),
        );
    }
}
