<?php

namespace App\Enums;

/**
 * ADR-192 — d'où vient la remise appliquée à une facture. Une seule par facture :
 * la plus avantageuse pour le patient parmi celles auxquelles il a droit.
 */
enum DiscountSource: string
{
    case Patient = 'PATIENT';
    case Staff = 'STAFF';
    case Vip = 'VIP';
    case Coupon = 'COUPON';

    public function label(): string
    {
        return match ($this) {
            self::Patient => 'Remise patient',
            self::Staff => 'Remise personnel',
            self::Vip => 'Remise VIP',
            self::Coupon => 'Coupon',
        };
    }

    /**
     * À montant égal, l'ordre de préférence : une décision prise pour ce patient,
     * puis son statut ; un coupon passe en dernier, pour ne pas consommer un code
     * qui n'apporte rien de plus.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Patient => 0,
            self::Staff => 1,
            self::Vip => 2,
            self::Coupon => 3,
        };
    }
}
