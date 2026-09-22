<?php

namespace App\Enums;

/**
 * CDC §9 names these four "équipe bloc" functions explicitly, transcribed
 * as-is.
 */
enum SurgicalTeamFunction: string
{
    case Surgeon = 'SURGEON';
    case Anesthetist = 'ANESTHETIST';
    case OrNurse = 'OR_NURSE';
    case Paramedical = 'PARAMEDICAL';

    /**
     * ADR-168 — le profil métier qui tient cette fonction au bloc (ADR-033) :
     * un anesthésiste est un compte au profil Anesthésiste, un infirmier de
     * bloc un compte au profil Infirmier de bloc — jamais n'importe quel compte.
     */
    public function profileCode(): string
    {
        return match ($this) {
            self::Surgeon => 'SURGEON',
            self::Anesthetist => 'ANESTHETIST',
            self::OrNurse => 'OR_NURSE',
            self::Paramedical => 'SURGICAL_PARAMEDICAL',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Surgeon => 'Chirurgien',
            self::Anesthetist => 'Anesthésiste',
            self::OrNurse => 'Infirmier de bloc',
            self::Paramedical => 'Paramédical',
        };
    }
}
