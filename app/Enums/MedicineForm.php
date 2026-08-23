<?php

namespace App\Enums;

enum MedicineForm: string
{
    case OralAmpouleEyeDropsDrops = 'ORAL_AMPOULE_EYE_DROPS_DROPS';
    case Tablet = 'TABLET';
    case Injectable = 'INJECTABLE';
    case Liquid = 'LIQUID';
    case ParapharmacyConsumable = 'PARAPHARMACY_CONSUMABLE';
    case OintmentCream = 'OINTMENT_CREAM';
    case Sachet = 'SACHET';
    case Syrup = 'SYRUP';
    case SuppositoryOvule = 'SUPPOSITORY_OVULE';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::OralAmpouleEyeDropsDrops => 'Ampoule buvable / Collyre / Goutte',
            self::Tablet => 'Comprimé',
            self::Injectable => 'Injectable',
            self::Liquid => 'Liquide',
            self::ParapharmacyConsumable => 'Parapharmacie / Consommable',
            self::OintmentCream => 'Pommade / Crème',
            self::Sachet => 'Sachet',
            self::Syrup => 'Sirop',
            self::SuppositoryOvule => 'Suppositoire / Ovule',
            self::Other => 'Autres',
        };
    }
}
