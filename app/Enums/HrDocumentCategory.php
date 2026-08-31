<?php

namespace App\Enums;

enum HrDocumentCategory: string
{
    case Personnel = 'PERSONNEL';
    case Diploma = 'DIPLOMA';
    case Identity = 'IDENTITY';
    case Contract = 'CONTRACT';
    case Leave = 'LEAVE';
    case Attestation = 'ATTESTATION';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Personnel => 'Dossier personnel',
            self::Diploma => 'Diplôme',
            self::Identity => 'Pièce d’identité',
            self::Contract => 'Contrat',
            self::Leave => 'Congé',
            self::Attestation => 'Attestation',
            self::Other => 'Autre document',
        };
    }
}
