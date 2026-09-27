<?php

namespace App\Enums;

/**
 * D'où venait la proposition qu'un médecin a retenue (ADR-111).
 *
 * Le médecin reste l'auteur du diagnostic ou de la ligne d'ordonnance ; ce
 * champ dit seulement ce qui la lui a proposée. Les deux sources sont
 * locales : elles ne lisent que la base du site, sans aucun service externe.
 */
enum ClinicalSuggestionSource: string
{
    /** Un protocole écrit par les médecins de la clinique. */
    case Protocol = 'PROTOCOL';

    /** Ce que les médecins de la clinique ont fait dans des cas semblables. */
    case ClinicPractice = 'CLINIC_PRACTICE';

    public function label(): string
    {
        return match ($this) {
            self::Protocol => 'Protocole de la clinique',
            self::ClinicPractice => 'Pratique de la clinique',
        };
    }
}
