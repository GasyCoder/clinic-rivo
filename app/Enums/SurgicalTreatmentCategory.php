<?php

namespace App\Enums;

enum SurgicalTreatmentCategory: string
{
    case Medication = 'MEDICATION';
    case Material = 'MATERIAL';
    case Serum = 'SERUM';
    case Antibiotic = 'ANTIBIOTIC';
    case AnalgesicNsaid = 'ANALGESIC_NSAID';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Medication => 'Médicament',
            self::Material => 'Matériel',
            self::Serum => 'Sérum',
            self::Antibiotic => 'Antibiotique',
            self::AnalgesicNsaid => 'Antalgique / AINS',
            self::Other => 'Autre',
        };
    }
}
