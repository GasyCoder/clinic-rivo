<?php

namespace App\Models;

use App\Enums\BonusAwardStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * L'avantage à l'acte d'une personne pour un mois : les lignes (article, quantité,
 * prix unitaire, total) sont figées à la validation. Versé hors RIVO, marqué versé ;
 * jamais supprimé, annulé avec un motif tant qu'il n'est pas versé.
 */
#[Fillable([
    'beneficiary_type', 'employee_id', 'partner_organization_id', 'beneficiary_name', 'period', 'lines',
    'total_amount', 'status', 'active_key', 'validated_at', 'validated_by', 'external_validated_by_uuid', 'external_validated_by_name',
])]
class AdvantageAward extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Un avantage ne se supprime pas : il s’annule avec un motif.'));
    }

    public static function activeKey(string $beneficiaryKey, string $period): string
    {
        return "A-{$beneficiaryKey}-{$period}";
    }

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'lines' => 'array',
            'total_amount' => 'decimal:2',
            'status' => BonusAwardStatus::class,
            'validated_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** « E12 » pour un employé, « P3 » pour un partenaire. */
    public function beneficiaryKey(): string
    {
        return $this->employee_id ? "E{$this->employee_id}" : "P{$this->partner_organization_id}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'partner_organization_id')->withTrashed();
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
