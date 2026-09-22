<?php

namespace App\Models;

use App\Enums\CareConsumableRequestStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * ADR-072 — what Soins actually used on the patient, notified to Pharmacy.
 * Never a prescription: Soins may not prescribe (client requirement of
 * 2026-09-10), which is why lines are restricted server-side to
 * MedicineForm::ParapharmacyConsumable. ADR-142 et ADR-169 — la Maternité et
 * le bloc empruntent le même circuit ; `source_module` dit qui a déclaré.
 */
#[Fillable([
    'request_number', 'source_module', 'episode_id', 'care_orientation_id', 'care_record_id', 'maternity_record_id',
    'surgical_request_id',
    'status', 'notes', 'requested_at', 'requested_by', 'served_at',
    'served_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
])]
class CareConsumableRequest extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        // ADR-010: a declared consumption is a clinical + stock trace.
        // Cancellation (while nothing moved) is the supported correction.
        static::deleting(fn () => throw new LogicException('Une demande de consommables Soins est une trace critique et ne peut pas être supprimée.'));
    }

    protected function casts(): array
    {
        return [
            'status' => CareConsumableRequestStatus::class,
            'requested_at' => 'datetime',
            'served_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function careOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'care_orientation_id');
    }

    public function careRecord(): BelongsTo
    {
        return $this->belongsTo(CareRecord::class);
    }

    public function maternityRecord(): BelongsTo
    {
        return $this->belongsTo(MaternityRecord::class);
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    /** Le service qui a déclaré ce matériel : Soins, Maternité (ADR-142) ou bloc (ADR-169). */
    public function sourceLabel(): string
    {
        return match ($this->source_module) {
            'MATERNITY' => 'Maternité',
            'SURGERY' => 'Bloc opératoire',
            default => 'Soins',
        };
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CareConsumableRequestLine::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    protected function auditModule(): ?string
    {
        return match ($this->source_module) {
            'MATERNITY' => 'maternity',
            'SURGERY' => 'surgery',
            default => 'care',
        };
    }
}
