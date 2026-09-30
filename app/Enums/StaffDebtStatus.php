<?php

namespace App\Enums;

/**
 * ADR-228 — où en est une dette du personnel.
 *
 *   REQUESTED  demandée par l'employé, attend la décision du DG
 *   APPROVED   accordée, pas encore versée (le versement se fait hors RIVO)
 *   ACTIVE     versée, en remboursement
 *   SETTLED    soldée : tout est remboursé
 *   REFUSED    refusée par le DG, avec son motif
 *   CANCELLED  retirée par l'employé avant décision, ou accord annulé avant versement
 *   WRITTEN_OFF le DG a remis le reste
 */
enum StaffDebtStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
    case Active = 'ACTIVE';
    case Settled = 'SETTLED';
    case Refused = 'REFUSED';
    case Cancelled = 'CANCELLED';
    case WrittenOff = 'WRITTEN_OFF';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'À décider',
            self::Approved => 'Accordée · à verser',
            self::Active => 'En remboursement',
            self::Settled => 'Soldée',
            self::Refused => 'Refusée',
            self::Cancelled => 'Annulée',
            self::WrittenOff => 'Remise',
        };
    }

    /** La couleur de sa pastille (`Badge` tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Active => 'primary',
            self::Settled => 'success',
            self::Refused, self::Cancelled => 'neutral',
            self::WrittenOff => 'danger',
        };
    }

    /** Une dette qui porte encore un reste à rembourser. */
    public function owes(): bool
    {
        return $this === self::Active;
    }

    /** Une dette close : plus aucun geste, sauf la lecture. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Settled, self::Refused, self::Cancelled, self::WrittenOff], true);
    }
}
