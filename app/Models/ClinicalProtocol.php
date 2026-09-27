<?php

namespace App\Models;

use App\Enums\PatientSex;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un protocole thérapeutique de la clinique (ADR-111).
 *
 * « Pour ce diagnostic, chez ce patient, voici ce que nous prescrivons. »
 * Il porte aussi les signes évocateurs qui permettent de proposer le
 * diagnostic lui-même. Rédigé par des médecins : le système l'applique, il ne
 * l'invente jamais.
 */
#[Fillable([
    'diagnostic_catalog_id', 'name', 'indications',
    'min_age_years', 'max_age_years', 'sex', 'min_weight_kg', 'max_weight_kg',
    'notes', 'is_active', 'created_by', 'updated_by',
])]
class ClinicalProtocol extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'indications' => 'array',
            'min_age_years' => 'integer',
            'max_age_years' => 'integer',
            'sex' => PatientSex::class,
            'min_weight_kg' => 'decimal:2',
            'max_weight_kg' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function diagnosticCatalog(): BelongsTo
    {
        return $this->belongsTo(DiagnosticCatalog::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClinicalProtocolLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Un protocole qui a servi reste lisible : les diagnostics et lignes
     * d'ordonnance qu'il a proposés le désignent (ADR-010).
     */
    public function isForceDeleteProtected(): bool
    {
        return Diagnosis::query()->where('clinical_protocol_id', $this->getKey())->exists()
            || PrescriptionLine::query()->where('clinical_protocol_id', $this->getKey())->exists();
    }

    protected function auditModule(): ?string
    {
        return 'medicine';
    }
}
