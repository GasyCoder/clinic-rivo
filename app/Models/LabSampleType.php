<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ADR-214 — un type de prélèvement (sanguin, écouvillon stérile, urines…) et
 * le tube qu'il demande d'ordinaire. Aucun prix : facturer un prélèvement, c'est
 * une prestation du Laboratoire au catalogue, au tarif du Super Admin (ADR-024).
 */
#[Fillable(['name', 'tube_type_id', 'instructions', 'is_active', 'created_by', 'updated_by'])]
class LabSampleType extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $type): void {
            $type->name = Str::squish((string) $type->name);
            $type->normalized_name = LabBacteriumFamily::normalize($type->name);
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tubeType(): BelongsTo
    {
        return $this->belongsTo(LabTubeType::class, 'tube_type_id')->withTrashed();
    }

    public function isForceDeleteProtected(): bool
    {
        return LabSample::query()->where('sample_type_id', $this->id)->exists();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
