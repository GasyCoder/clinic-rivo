<?php

namespace App\Models;

use App\Enums\HospitalStayStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-113 — le séjour d'un patient hospitalisé.
 *
 * Il commence à la demande du médecin (admission automatique) et se termine
 * par la sortie médicale. Il porte ce que la demande ne peut pas porter : la
 * chambre / le lit, l'heure réelle d'entrée et de sortie, et la fiche de
 * régime tenue pendant le séjour.
 */
#[Fillable([
    'episode_id', 'hospitalization_request_id', 'episode_orientation_id',
    'status', 'service', 'room_bed',
    'admitted_at', 'admitted_by',
    'discharged_at', 'discharged_by', 'medical_discharge_id',
    'cancelled_at', 'cancelled_by', 'cancellation_reason',
    'active_key',
])]
class HospitalStay extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'status' => HospitalStayStatus::class,
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function activeKeyFor(Episode $episode): string
    {
        return 'EPISODE_'.$episode->getKey();
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function hospitalizationRequest(): BelongsTo
    {
        return $this->belongsTo(HospitalizationRequest::class);
    }

    public function episodeOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class);
    }

    public function medicalDischarge(): BelongsTo
    {
        return $this->belongsTo(MedicalDischarge::class);
    }

    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by');
    }

    public function dischargedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discharged_by');
    }

    public function dietEntries(): HasMany
    {
        return $this->hasMany(HospitalDietEntry::class);
    }

    public function isActive(): bool
    {
        return $this->status === HospitalStayStatus::Active;
    }

    /** Dossier clinique : clos ou annulé, jamais détruit (ADR-010). */
    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'hospitalization';
    }
}
