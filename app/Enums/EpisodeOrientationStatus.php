<?php

namespace App\Enums;

enum EpisodeOrientationStatus: string
{
    case Pending = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::InProgress => 'En cours',
            self::Completed => 'Orienté',
            self::Cancelled => 'Annulé',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::InProgress], true);
    }
}
