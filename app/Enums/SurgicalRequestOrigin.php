<?php

namespace App\Enums;

/**
 * ADR-159 — d'où vient une demande du bloc.
 *
 * Le bloc ne crée pas ses propres demandes : elles naissent là où la décision
 * est prise. Le propriétaire en a nommé deux ; la troisième existe déjà et
 * n'est pas retirée — une césarienne décidée en Maternité crée sa demande
 * chirurgicale sans passer par la consultation (ADR-067). La quatrième est le
 * patient déjà au lit que l'on descend au bloc (ADR-160) : la décision est
 * prise sur le séjour, pas dans une consultation d'arrivée.
 */
enum SurgicalRequestOrigin: string
{
    case Reception = 'RECEPTION';
    case Medicine = 'MEDICINE';
    case Maternity = 'MATERNITY';
    case Hospitalization = 'HOSPITALIZATION';

    public function label(): string
    {
        return match ($this) {
            self::Reception => 'Réception',
            self::Medicine => 'Médecine',
            self::Maternity => 'Maternité',
            self::Hospitalization => 'Hospitalisation',
        };
    }

    /** Ce que l'origine dit du dossier, en une phrase. */
    public function description(): string
    {
        return match ($this) {
            self::Reception => 'Acte demandé à l’arrivée du patient, selon son besoin.',
            self::Medicine => 'Décidé par le médecin en consultation, dans la conduite à tenir.',
            self::Maternity => 'Césarienne décidée en Maternité.',
            self::Hospitalization => 'Patient hospitalisé transféré au bloc depuis son séjour.',
        };
    }
}
