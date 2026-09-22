<?php

namespace App\Enums;

/**
 * ADR-170 — l'état d'une condition posée par une autorisation sous conditions.
 *
 * Deux états seulement. Un troisième, « levée sans être remplie » (waiver),
 * n'est pas créé : le CDC ne décrit ni la clearance ni qui pourrait passer
 * outre une condition anesthésique, et l'inventer reviendrait à donner à
 * quelqu'un — sans savoir à qui — le droit d'ignorer une réserve de
 * l'anesthésiste. Une condition qui ne s'applique plus se résout par celui qui
 * l'a posée, avec sa note.
 */
enum AnesthesiaClearanceConditionStatus: string
{
    case Open = 'OPEN';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'À lever',
            self::Resolved => 'Levée',
        };
    }
}
