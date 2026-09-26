<?php

namespace App\Models;

use App\Enums\PregnancyDatingMethod;
use App\Enums\PregnancyStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dossier obstétrical longitudinal d'une patiente.
 *
 * Une grossesse n'est ni un passage ni une consultation. Elle n'est jamais
 * supprimée : une fin sans accouchement utilise le statut ENDED, tandis que
 * chaque rencontre clinique reste un MaternityRecord rattaché à son Episode.
 */
#[Fillable([
    'patient_id', 'status', 'last_menstrual_period', 'estimated_due_date',
    'dating_method', 'dating_confirmed_at', 'dating_confirmed_by',
    'dating_correction_reason', 'gravidity', 'parity', 'risk_factors',
    'started_at', 'delivered_at', 'ended_at', 'created_by', 'updated_by',
])]
class Pregnancy extends Model
{
    use Auditable, HasUuid;

    protected $attributes = [
        'status' => PregnancyStatus::Ongoing->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => PregnancyStatus::class,
            'dating_method' => PregnancyDatingMethod::class,
            'last_menstrual_period' => 'date',
            'estimated_due_date' => 'date',
            'dating_confirmed_at' => 'datetime',
            'started_at' => 'date',
            'delivered_at' => 'datetime',
            'ended_at' => 'datetime',
            'gravidity' => 'integer',
            'parity' => 'integer',
        ];
    }

    public function scopeOngoing(Builder $query): Builder
    {
        return $query->where('status', PregnancyStatus::Ongoing->value);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function maternityRecords(): HasMany
    {
        return $this->hasMany(MaternityRecord::class)->orderBy('created_at')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function datingConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dating_confirmed_by');
    }

    public function reference(): string
    {
        $year = $this->started_at?->format('Y') ?? $this->created_at?->format('Y') ?? now()->format('Y');

        return sprintf('G-%s-%04d', $year, $this->getKey());
    }

    protected function auditModule(): ?string
    {
        return 'maternity';
    }
}
