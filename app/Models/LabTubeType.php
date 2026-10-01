<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ADR-214 — un type de tube du référentiel du site (Sec, EDTA, Citrate…), avec
 * la couleur de son bouchon : c'est elle que le préleveur reconnaît d'abord.
 */
#[Fillable(['code', 'name', 'cap_color', 'color_hex', 'is_active', 'created_by', 'updated_by'])]
class LabTubeType extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $tube): void {
            $tube->code = Str::upper(Str::squish((string) $tube->code));
            $tube->normalized_code = LabBacteriumFamily::normalize($tube->code);
            $tube->name = Str::squish((string) $tube->name);
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sampleTypes(): HasMany
    {
        return $this->hasMany(LabSampleType::class, 'tube_type_id');
    }

    /** Un tube porté par un prélèvement enregistré ne se supprime jamais. */
    public function isForceDeleteProtected(): bool
    {
        return LabSample::query()->where('tube_type_id', $this->id)->exists()
            || $this->sampleTypes()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
