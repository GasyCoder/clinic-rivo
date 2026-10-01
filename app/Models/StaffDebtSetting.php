<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-229 — les règles des dettes du personnel sur ce site, réglées par le Super Admin
 * depuis le portail (Finance › Dettes du personnel) : ouverture des demandes, montant
 * minimum et maximum, durée maximale, part du salaire que les mensualités peuvent
 * prendre, dettes en cours par employé, ancienneté minimale, stagiaires, et tranches
 * d'intérêt.
 *
 * Une seule ligne. Une valeur vide ne pose aucune limite ; sans ligne, aucune limite
 * et aucun intérêt — le comportement d'avant.
 */
#[Fillable([
    'requests_open', 'closed_message', 'min_amount', 'max_amount', 'max_months', 'max_salary_share',
    'max_open_debts', 'min_seniority_months', 'exclude_interns', 'interest_tiers',
    'penalty_rate', 'penalty_grace_days', 'penalty_cap_rate',
    'updated_by', 'external_updated_by_uuid', 'external_updated_by_name',
])]
class StaffDebtSetting extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'requests_open' => 'boolean',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'max_months' => 'integer',
            'max_salary_share' => 'integer',
            'max_open_debts' => 'integer',
            'min_seniority_months' => 'integer',
            'exclude_interns' => 'boolean',
            'interest_tiers' => 'array',
            'penalty_rate' => 'decimal:2',
            'penalty_grace_days' => 'integer',
            'penalty_cap_rate' => 'decimal:2',
        ];
    }

    protected function auditModule(): ?string
    {
        return 'finance';
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
