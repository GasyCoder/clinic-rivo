<?php

namespace App\Models;

use App\Enums\AllergenCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Controlled, site-local list used to help clinical staff select a known
 * allergen. It is deliberately separate from the billable catalog and never
 * replaces the reaction/severity recorded in the patient's medical history.
 */
#[Fillable(['code', 'name', 'category', 'active'])]
class AllergenReference extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'category' => AllergenCategory::class,
            'active' => 'boolean',
        ];
    }

    public function patientAllergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    protected function auditModule(): ?string
    {
        return 'medical_reference';
    }
}
