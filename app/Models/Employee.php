<?php

namespace App\Models;

use App\Enums\EmployeeRemunerationType;
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
    'employee_number', 'user_id', 'department_id', 'job_title_id', 'civility',
    'first_name', 'last_name', 'sex', 'birth_date', 'hire_date', 'birth_place',
    'identity_document_type', 'identity_document_number',
    'identity_document_issued_on', 'identity_document_issued_at',
    'marital_status', 'children_count', 'diploma', 'education_level',
    'children_details', 'children', 'badge', 'blouse', 'profession', 'phone', 'email',
    // Fiche du personnel : second contact, tailles de tenue et matériel remis.
    'phone_secondary', 'tshirt_size', 'blouse_size', 'bloc_outfit', 'shoe_size', 'scrub_cap', 'clog',
    'address', 'address_entry_id', 'observation', 'active',
    'photo_path', 'photo_updated_at',
    // ADR-206 — rémunération déclarée et compte bancaire (droits employees.payroll.*).
    'remuneration_type', 'remuneration_amount', 'bank_account_number', 'bank_account_holder',
    'salary_payment_mode', 'mobile_money_accounts',
    // ADR-221 — la banque du compte, choisie dans le référentiel des banques.
    'bank_id',
])]
class Employee extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    /** ADR-190 — ses adresses email professionnelles, ouvertes ou non. */
    public function professionalMailboxes(): HasMany
    {
        return $this->hasMany(ProfessionalMailbox::class);
    }

    protected function casts(): array
    {
        return [
            'civility' => PatientCivility::class,
            'sex' => PatientSex::class,
            'birth_date' => 'date',
            'hire_date' => 'date',
            'identity_document_type' => IdentityDocumentType::class,
            'identity_document_issued_on' => 'date',
            'marital_status' => MaritalStatus::class,
            'children_count' => 'integer',
            'children' => 'array',
            'salary_payment_mode' => \App\Enums\SalaryPaymentMode::class,
            'mobile_money_accounts' => 'array',
            'active' => 'boolean',
            'photo_updated_at' => 'datetime',
            'remuneration_type' => EmployeeRemunerationType::class,
            'remuneration_amount' => 'decimal:2',
            // ADR-197 — « aucun accès nécessaire », décidé par le Super Admin.
            'access_waived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ADR-221 — la banque de son compte, choisie dans le module Banques. */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /** ADR-221 — ses avantages et primes déclarés, retirés compris seulement avec withTrashed(). */
    public function benefits(): HasMany
    {
        return $this->hasMany(EmployeeBenefit::class);
    }

    public function addressEntry(): BelongsTo
    {
        return $this->belongsTo(AddressEntry::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'department_id');
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'job_title_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function interimLeaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'interim_employee_id');
    }

    public function planningShifts(): HasMany
    {
        return $this->hasMany(PlanningShift::class);
    }

    public function hrDocuments(): HasMany
    {
        return $this->hasMany(HrDocument::class);
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

    public function staffBlockCreditMovements(): HasMany
    {
        return $this->hasMany(StaffBlockCreditMovement::class)->latest('id');
    }

    /** ADR-194 — la photo d'identité 4 × 4, sur le disque privé. */
    public function hasPhoto(): bool
    {
        return filled($this->photo_path);
    }

    public function isAvailableForPatientLink(): bool
    {
        return $this->active && ! $this->trashed();
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->patientLinks()->exists()
            || $this->episodeStaffCoverages()->exists()
            || $this->staffBlockCreditMovements()->exists()
            || $this->contracts()->withTrashed()->exists()
            || $this->attendanceRecords()->exists()
            || $this->leaveRequests()->exists()
            || $this->interimLeaveRequests()->exists()
            || $this->planningShifts()->exists()
            || $this->hrDocuments()->withTrashed()->exists()
            || $this->benefits()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
