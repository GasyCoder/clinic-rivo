<?php

namespace App\Models;

use App\Enums\HospitalStayEndReason;
use App\Enums\HospitalStayStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    'status', 'service', 'room_bed', 'hospital_bed_id',
    'admitted_at', 'admitted_by',
    'discharged_at', 'discharged_by', 'medical_discharge_id',
    'end_reason', 'medical_referral_id',
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
            'end_reason' => HospitalStayEndReason::class,
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function activeKeyFor(Episode $episode): string
    {
        return 'EPISODE_'.$episode->getKey();
    }

    /**
     * ADR-164 — un lit n'a qu'un séjour en cours. La clé se pose tant que le
     * séjour est actif et porte un lit, et disparaît à sa fin : les trois
     * chemins de fin (sortie, départ en transfert, annulation) libèrent donc
     * le lit sans y penser. L'index unique est la garde finale, en base.
     */
    public static function bedActiveKeyFor(int $bedId): string
    {
        return 'BED_'.$bedId;
    }

    protected static function booted(): void
    {
        static::saving(function (self $stay): void {
            $stay->bed_active_key = $stay->status === HospitalStayStatus::Active && $stay->hospital_bed_id
                ? self::bedActiveKeyFor((int) $stay->hospital_bed_id)
                : null;
        });

        // Une instance chargée avant l'attribution du lit ne sait pas qu'il y en
        // a un : la clé nulle en mémoire ne serait pas réécrite. La fin du
        // séjour libère donc le lit en base, quel que soit l'état en mémoire.
        static::saved(function (self $stay): void {
            if ($stay->status !== HospitalStayStatus::Active) {
                static::query()->whereKey($stay->getKey())->whereNotNull('bed_active_key')->update(['bed_active_key' => null]);
            }
        });
    }

    /** ADR-164 — le lit du séjour ; conservé après la fin, pour l'historique. */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(HospitalBed::class, 'hospital_bed_id')->withTrashed();
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /** ADR-161 — l'historique des emplacements (service, lit, niveau de soins). */
    public function movements(): HasMany
    {
        return $this->hasMany(HospitalStayMovement::class)->orderBy('started_at')->orderBy('id');
    }

    /** L'emplacement actuel : le mouvement qui n'est pas encore terminé. */
    public function currentMovement(): HasOne
    {
        return $this->hasOne(HospitalStayMovement::class)->whereNull('ended_at')->latestOfMany('started_at');
    }

    /** ADR-161 — la surveillance répétée pendant le séjour. */
    public function vitalReadings(): HasMany
    {
        return $this->hasMany(VitalSignReading::class);
    }

    /** ADR-162 — ce que le séjour a demandé lui-même, sans consultation. */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function labRequests(): HasMany
    {
        return $this->hasMany(LabRequest::class);
    }

    public function imagingRequests(): HasMany
    {
        return $this->hasMany(ImagingRequest::class);
    }

    public function careOrders(): HasMany
    {
        return $this->hasMany(CareOrder::class);
    }

    /** ADR-162 — la note quotidienne courte S/O/A/P. */
    public function notes(): HasMany
    {
        return $this->hasMany(HospitalStayNote::class);
    }

    /** ADR-161 — le transfert dont le départ a terminé le séjour. */
    public function medicalReferral(): BelongsTo
    {
        return $this->belongsTo(MedicalReferral::class);
    }

    /**
     * ADR-161 — un séjour qui se termine ferme son emplacement. Appelé par
     * les trois chemins de fin (sortie médicale, départ en transfert,
     * annulation) : l'historique ne laisse jamais un lit occupé après la fin.
     */
    public function closeCurrentMovement(\DateTimeInterface|string|null $at = null): void
    {
        $this->movements()->getQuery()->whereNull('ended_at')->update([
            'ended_at' => $at ?? now(),
            'updated_at' => now(),
        ]);
    }

    public function hospitalizationRequest(): BelongsTo
    {
        return $this->belongsTo(HospitalizationRequest::class);
    }

    /** ADR-147 — les diagnostics posés au terme du séjour, append-only. */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(HospitalStayDiagnosis::class);
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
