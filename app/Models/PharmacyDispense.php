<?php

namespace App\Models;

use App\Enums\PharmacyDispenseStatus;
use App\Enums\PharmacyDispenseType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'type', 'prescription_id', 'patient_id', 'episode_id', 'hospital_stay_id', 'invoice_id',
    'customer_name', 'customer_phone', 'external_prescription_reference',
    'external_prescriber', 'status', 'requested_at', 'requested_by',
    'completed_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
])]
class PharmacyDispense extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une demande de dispensation est une trace critique et ne peut pas être supprimée.'));
    }

    protected function casts(): array
    {
        return [
            'type' => PharmacyDispenseType::class,
            'status' => PharmacyDispenseStatus::class,
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PharmacyDispenseLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PharmacyDispenseEvent::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }

    /**
     * ADR-162 — la délivrance au service d'un patient hospitalisé. Elle
     * n'attend pas le règlement : le patient est au lit, le traitement ne
     * peut pas attendre que la famille passe à la Caisse. La facture est
     * préparée comme toujours, et seule la Caisse encaisse (ADR-012).
     */
    public function isWardDispense(): bool
    {
        return $this->hospital_stay_id !== null;
    }

    /** ADR-162 — la demande faite depuis le séjour, sans consultation. */
    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }
}
