<?php

namespace App\Models;

use App\Enums\IdentityDocumentType;
use App\Enums\MaritalStatus;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A local HR identity. It is deliberately separate from User: an employee
 * does not need a login, and an authentication account is not a personnel
 * record. Only this administrative subset may be exposed to Reception.
 */
#[Fillable([
    'employee_number', 'user_id', 'civility', 'first_name', 'last_name',
    'sex', 'birth_date', 'identity_document_type', 'identity_document_number',
    'marital_status', 'children_count', 'profession', 'phone', 'email',
    'address', 'address_entry_id', 'active',
])]
class Employee extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'civility' => PatientCivility::class,
            'sex' => PatientSex::class,
            'birth_date' => 'date',
            'identity_document_type' => IdentityDocumentType::class,
            'marital_status' => MaritalStatus::class,
            'children_count' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addressEntry(): BelongsTo
    {
        return $this->belongsTo(AddressEntry::class);
    }

    public function patientLinks(): HasMany
    {
        return $this->hasMany(PatientStaffLink::class);
    }

    public function activePatientLink(): HasOne
    {
        return $this->hasOne(PatientStaffLink::class)->whereNull('ended_at');
    }

    public function episodeStaffCoverages(): HasMany
    {
        return $this->hasMany(EpisodeStaffCoverage::class);
    }

    public function isAvailableForPatientLink(): bool
    {
        return $this->active && ! $this->trashed();
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->patientLinks()->exists() || $this->episodeStaffCoverages()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
