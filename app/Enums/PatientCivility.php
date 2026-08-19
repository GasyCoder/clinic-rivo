<?php

namespace App\Enums;

/**
 * Distinct from `sex` (M/F, used throughout for clinical/administrative
 * logic) — this is purely how staff addresses the patient. Not requested
 * by the CDC; added per Réception's own feedback on the arrival form.
 */
enum PatientCivility: string
{
    case Mr = 'MR';
    case Mrs = 'MRS';
    case Girl = 'GIRL';
    case Boy = 'BOY';
}
