<?php

namespace App\Models;

use App\Enums\EmployeeBenefitFrequency;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-221 — un avantage ou une prime déclaré pour un employé : un type, un
 * montant (facultatif pour un avantage en nature), un motif, une fréquence.
 *
 * Une déclaration du RH (ADR-206) : aucun total, aucun net n'en est calculé.
 * Retiré, il n'est jamais supprimé : il s'archive avec son motif (ADR-009).
 */
#[Fillable([
    'employee_id', 'benefit_type_id', 'amount', 'reason', 'frequency', 'starts_on', 'ends_on',
    'created_by', 'updated_by', 'external_created_by_uuid', 'external_created_by_name',
])]
class EmployeeBenefit extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'frequency' => EmployeeBenefitFrequency::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function benefitType(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'benefit_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** En cours aujourd'hui : commencé, et sans fin ou pas encore fini. */
    public function isCurrent(): bool
    {
        return ! $this->trashed()
            && $this->starts_on && ! $this->starts_on->isFuture()
            && (! $this->ends_on || ! $this->ends_on->isPast() || $this->ends_on->isToday());
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
