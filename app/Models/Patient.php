<?php

namespace App\Models;

use App\Enums\PatientSex;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * CDC §21. Owned by Réception (AI_CONTEXT.md: "gère... l'identité
 * administrative du patient") — never carries payment/medical data itself,
 * only the administrative identity. Not on the CDC §11 critical-data list,
 * so force_delete is gated purely by the `patient.force_delete` permission
 * (ADR-008), not hard-blocked at the model level like a settled payment
 * would be.
 */
#[Fillable(['patient_number', 'first_name', 'last_name', 'birth_date', 'sex', 'phone', 'address'])]
class Patient extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sex' => PatientSex::class,
        ];
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
