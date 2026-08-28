<?php

namespace App\Enums;

enum StaffCoveragePolicy: string
{
    case Unclassified = 'UNCLASSIFIED';
    case OrdinaryFullCoverage = 'ORDINARY_FULL_COVERAGE';
    case BlockCredit = 'BLOCK_CREDIT';
    case NotCovered = 'NOT_COVERED';

    public function label(): string
    {
        return match ($this) {
            self::Unclassified => 'À classifier',
            self::OrdinaryFullCoverage => 'Prise en charge Personnel à 100 %',
            self::BlockCredit => 'Crédit forfaitaire Bloc',
            self::NotCovered => 'Non couvert par le régime Personnel',
        };
    }

    public function isResolvedWithoutCredit(): bool
    {
        return in_array($this, [self::OrdinaryFullCoverage, self::NotCovered], true);
    }
}
