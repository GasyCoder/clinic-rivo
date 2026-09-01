<?php

namespace App\Enums;

enum CatalogModule: string
{
    case Reception = 'RECEPTION';
    case Medicine = 'MEDICINE';
    case Care = 'CARE';
    case Laboratory = 'LABORATORY';
    case Pharmacy = 'PHARMACY';
    case Surgery = 'SURGERY';
    case Administration = 'ADMINISTRATION';
    // ECG / échographie demandées en consultation. Contrairement à
    // Laboratoire, aucun workspace dédié n'existe : la demande et le
    // résultat restent portés par Médecine (voir ImagingRequest).
    case Imaging = 'IMAGING';
    // Specialized workspaces that can receive an Episode orientation.
    case Maternity = 'MATERNITY';
    case Hospitalization = 'HOSPITALIZATION';
    case Transfer = 'TRANSFER';
    case Pediatrics = 'PEDIATRICS';

    public function label(): string
    {
        return match ($this) {
            self::Reception => 'Réception',
            self::Medicine => 'Médecine',
            self::Care => 'Soins',
            self::Laboratory => 'Laboratoire',
            self::Pharmacy => 'Pharmacie',
            self::Surgery => 'Chirurgie',
            self::Administration => 'Administration',
            self::Maternity => 'Maternité',
            self::Hospitalization => 'Hospitalisation',
            self::Transfer => 'Transfert',
            self::Pediatrics => 'Pédiatrie',
            self::Imaging => 'Imagerie',
        };
    }
}
