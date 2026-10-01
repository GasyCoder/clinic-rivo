<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'employee_id', 'contract_type_id', 'reference_number', 'signed_on',
    'starts_on', 'trial_ends_on', 'ends_on', 'observation',
    'internship_field_id', 'internship_school', 'internship_level', 'internship_supervisor_id',
])]
class EmploymentContract extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'signed_on' => 'date',
            'starts_on' => 'date',
            'trial_ends_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'contract_type_id');
    }

    /** ADR-194 — la filière du stage, quand le contrat est un contrat de stage. */
    public function internshipField(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'internship_field_id')->withTrashed();
    }

    public function internshipSupervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'internship_supervisor_id')->withTrashed();
    }

    public function isInternship(): bool
    {
        return (bool) $this->contractType?->isInternshipContractType();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrDocument::class);
    }

    /**
     * ADR-236 — un contrat ne se détruit que s'il n'a produit aucun document (pièce RH,
     * document généré) : saisi à tort, en double. Sinon il reste archivé, restaurable.
     */
    public function isForceDeleteProtected(): bool
    {
        return $this->usageLabels() !== [];
    }

    /** @return list<string> ce qui retient le contrat, en mots : « 2 pièces RH » */
    public function usageLabels(): array
    {
        $labels = [];
        foreach ([
            'hr_documents' => ['pièce RH', 'pièces RH'],
            'generated_documents' => ['document généré', 'documents générés'],
        ] as $table => [$singular, $plural]) {
            $count = DB::table($table)->where('employment_contract_id', $this->getKey())->count();
            if ($count > 0) {
                $labels[] = $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
            }
        }

        return $labels;
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
