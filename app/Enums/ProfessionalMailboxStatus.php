<?php

namespace App\Enums;

/**
 * ADR-190 — où en est l'adresse email professionnelle d'un employé.
 *
 * Demandée par le RH du site, créée ou refusée par le Super Admin (qui seul
 * parle à l'hébergeur), suspendue au départ de l'employé : jamais supprimée.
 */
enum ProfessionalMailboxStatus: string
{
    case Requested = 'REQUESTED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Demande en attente',
            self::Active => 'Active',
            self::Suspended => 'Suspendue',
            self::Rejected => 'Refusée',
            self::Cancelled => 'Demande annulée',
        };
    }

    /** L'adresse est réservée : demandée, ouverte ou suspendue (une boîte suspendue existe toujours chez l'hébergeur). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Active, self::Suspended], true);
    }

    /** @return array<int, string> */
    public static function openValues(): array
    {
        return array_map(fn (self $status) => $status->value, array_filter(self::cases(), fn (self $status) => $status->isOpen()));
    }
}
