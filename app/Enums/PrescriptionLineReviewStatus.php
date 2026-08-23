<?php

namespace App\Enums;

enum PrescriptionLineReviewStatus: string
{
    case Pending = 'PENDING';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de validation',
            self::Resolved => 'Traité',
        };
    }
}
