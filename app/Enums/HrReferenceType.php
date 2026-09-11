<?php

namespace App\Enums;

enum HrReferenceType: string
{
    case Department = 'DEPARTMENT';
    case JobTitle = 'JOB_TITLE';
    case ContractType = 'CONTRACT_TYPE';
    case LeaveType = 'LEAVE_TYPE';
    case AttestationType = 'ATTESTATION_TYPE';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Département',
            self::JobTitle => 'Fonction',
            self::ContractType => 'Type de contrat',
            self::LeaveType => 'Type de congé / permission',
            self::AttestationType => 'Type d’attestation',
        };
    }
}
