<?php

namespace App\Enums;

/**
 * A visitor entry is non-clinical: it never creates a Patient or Episode.
 * These two categories were confirmed by the project owner on 2026-08-20.
 */
enum VisitorCategory: string
{
    case Professional = 'PROFESSIONAL';
    case PatientOrFamilyVisit = 'PATIENT_OR_FAMILY_VISIT';
}
