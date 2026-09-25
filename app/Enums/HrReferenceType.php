<?php

namespace App\Enums;

enum HrReferenceType: string
{
    case Department = 'DEPARTMENT';
    case JobTitle = 'JOB_TITLE';
    case ContractType = 'CONTRACT_TYPE';
    case LeaveType = 'LEAVE_TYPE';
    case AttestationType = 'ATTESTATION_TYPE';
    // ADR-194 — la filière d'un stage (Infirmier, Sage-femme…).
    case InternshipField = 'INTERNSHIP_FIELD';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Département',
            self::JobTitle => 'Fonction',
            self::ContractType => 'Type de contrat',
            self::LeaveType => 'Type de congé / permission',
            self::AttestationType => 'Type d’attestation',
            self::InternshipField => 'Filière de stage',
        };
    }
}
