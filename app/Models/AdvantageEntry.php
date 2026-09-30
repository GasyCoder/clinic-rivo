<?php

namespace App\Models;

use App\Enums\AdvantageEntryStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-227 — un avantage saisi à la main pour un médecin (ou toute personne dont les
 * avantages sont ouverts) : montant, motif, mois de paie. En attente jusqu'à la paie
 * de son mois, qui le marque payé. Supprimé (non payé seulement), il reste tracé.
 */
#[Fillable([
    'employee_id', 'period', 'amount', 'reason', 'status', 'salary_payment_id',
    'created_by', 'external_created_by_uuid', 'external_created_by_name',
    'updated_by', 'external_updated_by_uuid', 'external_updated_by_name',
])]
class AdvantageEntry extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => 'decimal:2',
            'status' => AdvantageEntryStatus::class,
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === AdvantageEntryStatus::Paid;
    }

    /** Un avantage payé fait partie d'une paie : il ne se détruit jamais. */
    public function isForceDeleteProtected(): bool
    {
        return $this->isPaid() || $this->salary_payment_id !== null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function salaryPayment(): BelongsTo
    {
        return $this->belongsTo(SalaryPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
