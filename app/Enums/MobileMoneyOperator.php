<?php

namespace App\Enums;

/**
 * Les opérateurs de Mobile Money à Madagascar. Le préfixe d'un numéro propose son
 * opérateur à l'écran (034/038 Yas, 032/037 Orange, 033 Airtel) ; le choix reste au RH.
 */
enum MobileMoneyOperator: string
{
    case Yas = 'YAS';
    case Orange = 'ORANGE';
    case Airtel = 'AIRTEL';

    public function label(): string
    {
        return match ($this) {
            self::Yas => 'MVola (Yas)',
            self::Orange => 'Orange Money',
            self::Airtel => 'Airtel Money',
        };
    }
}
