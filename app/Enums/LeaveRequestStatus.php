<?php

namespace App\Enums;

enum LeaveRequestStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Approved => 'Acceptée',
            self::Rejected => 'Refusée',
            self::Cancelled => 'Annulée',
        };
    }
}
