<?php

namespace App\Enums;

/**
 * A Soins consumable request declares what was *already used* on the
 * patient (compress, plaster, cotton) and notifies Pharmacy so the stock
 * exit is recorded by the module that owns the stock.
 *
 * It is deliberately NOT PharmacyDispenseStatus: a dispensation waits for
 * a fully settled invoice before any physical exit (ADR-049), which cannot
 * apply to an item already consumed at the bedside (ADR-072).
 */
enum CareConsumableRequestStatus: string
{
    case Pending = 'PENDING';
    case PartiallyServed = 'PARTIALLY_SERVED';
    case Served = 'SERVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À servir par la Pharmacie',
            self::PartiallyServed => 'Servie partiellement',
            self::Served => 'Servie',
            self::Cancelled => 'Annulée',
        };
    }

    public function canBeServed(): bool
    {
        return in_array($this, [self::Pending, self::PartiallyServed], true);
    }

    /** Cancellation stays possible only while no stock has physically moved. */
    public function canBeCancelled(): bool
    {
        return $this === self::Pending;
    }
}
