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
}
