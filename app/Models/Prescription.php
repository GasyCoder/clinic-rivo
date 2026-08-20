<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use App\Exceptions\InvalidPrescriptionTransitionException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CDC §19 lists "prescriptions utiles" in the inter-site transfer payload
 * (hence HasUuid, unlike Consultation/Diagnosis). CDC GitHub §15 lists
 * `prescriptions.cancel`, not delete — see cancel() below, same pattern as
 * Episode::cancel().
 */
#[Fillable(['consultation_id', 'status', 'cancel_reason', 'cancelled_at'])]
class Prescription extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'status' => PrescriptionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PrescriptionLine::class);
    }

    /**
     * Terminal — records its own 'cancel' audit entry (with the reason)
     * instead of Auditable's generic 'update', via auditableSkipsChange()
     * below. Mirrors Episode::cancel() exactly.
     */
    public function cancel(string $reason): void
    {
        if ($this->status !== PrescriptionStatus::Active) {
            throw new InvalidPrescriptionTransitionException(
                $this,
                PrescriptionStatus::Cancelled->value,
                $this->status->value,
            );
        }

        $this->status = PrescriptionStatus::Cancelled;
        $this->cancelled_at = now();
        $this->save();

        app(Auditor::class)->record(
            'cancel',
            entity: $this,
            reason: $reason,
            module: $this->auditModule(),
        );
    }

    protected function auditableSkipsChange(array $changes): bool
    {
        return $this->status === PrescriptionStatus::Cancelled && array_key_exists('status', $changes);
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
