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
}
