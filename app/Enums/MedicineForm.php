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

    /**
     * Les formes qui ne se dosent pas (ADR-110).
     *
     * Une compresse, un sparadrap ou une paire de gants s'utilise en nombre,
     * pas en milligrammes. La liste vit ici, avec les formes elles-mêmes :
     * l'écran et la validation la lisent au même endroit, et ni l'un ni
     * l'autre ne la déduit d'un libellé (ADR-052).
     *
     * @return array<int, string>
     */
    public static function undosedValues(): array
    {
        return [self::ParapharmacyConsumable->value];
    }

    public function isDosed(): bool
    {
        return ! in_array($this->value, self::undosedValues(), true);
    }

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
