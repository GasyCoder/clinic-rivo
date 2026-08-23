<?php

namespace App\Models;

use App\Enums\IdentityDocumentType;
use App\Enums\MaritalStatus;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Enums\PatientType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CDC §21. Owned by Réception (AI_CONTEXT.md: "gère... l'identité
 * administrative du patient") — never carries payment data itself, only
 * administrative identity plus the permanent "dossier patient" fields the
 * client CDCF calls out (contact d'urgence, antécédents, allergies via
 * relations below). Not on the CDC §11 critical-data list, so force_delete
 * is gated purely by the `patients.force_delete` permission (ADR-008), not
 * hard-blocked at the model level like a settled payment would be — but is
 * still refused whenever dependent clinical/administrative records exist
 * (see isForceDeleteProtected()).
 */
#[Fillable([
    'patient_number', 'patient_type', 'first_name', 'last_name', 'birth_date',
    'birth_date_is_approximate', 'declared_age', 'declared_age_at',
    'sex', 'civility', 'identity_document_type', 'identity_document_number',
    'marital_status', 'children_count', 'profession', 'phone', 'email',
    'address', 'address_entry_id',
])]
class Patient extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'birth_date_is_approximate' => 'boolean',
            'declared_age' => 'integer',
            'declared_age_at' => 'datetime',
            'sex' => PatientSex::class,
            'civility' => PatientCivility::class,
            'identity_document_type' => IdentityDocumentType::class,
            'patient_type' => PatientType::class,
            'marital_status' => MaritalStatus::class,
            'children_count' => 'integer',
        ];
    }

    public function addressEntry(): BelongsTo
    {
        return $this->belongsTo(AddressEntry::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function antecedents(): HasMany
    {
        return $this->hasMany(PatientAntecedent::class);
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    public function staffLinks(): HasMany
    {
        return $this->hasMany(PatientStaffLink::class);
    }

    public function activeStaffLink(): HasOne
    {
        return $this->hasOne(PatientStaffLink::class)->whereNull('ended_at');
    }

    public function mutualCoverages(): HasMany
    {
        return $this->hasMany(PatientMutualCoverage::class);
    }

    public function activeMutualCoverage(): HasOne
    {
        return $this->hasOne(PatientMutualCoverage::class)->whereNull('effective_until');
    }

    /**
     * episodes/patient_antecedents/patient_allergies all use
     * restrictOnDelete() foreign keys (never orphaned) — without this
     * override, force_delete on a patient with any of these would surface
     * as a raw DB constraint violation instead of the clean
     * ForceDeleteForbiddenException every other protected model produces.
     */
    public function isForceDeleteProtected(): bool
    {
        return $this->episodes()->exists()
            || $this->invoices()->exists()
            || $this->antecedents()->exists()
            || $this->allergies()->exists()
            || $this->staffLinks()->exists()
            || $this->mutualCoverages()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
