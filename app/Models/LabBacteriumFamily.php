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
 * ADR-213 — une famille de germes du référentiel de microbiologie
 * (Entérobactéries, Staphylocoques…). Ses bactéries partagent la même liste
 * d'antibiotiques à tester : c'est elle qui compose l'antibiogramme.
 */
#[Fillable(['name', 'is_active', 'created_by', 'updated_by'])]
class LabBacteriumFamily extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $family): void {
            $family->name = Str::squish((string) $family->name);
            $family->normalized_name = self::normalize($family->name);
        });
    }

    public static function normalize(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function bacteria(): HasMany
    {
        return $this->hasMany(LabBacterium::class, 'family_id')->orderBy('name');
    }

    public function antibiotics(): HasMany
    {
        return $this->hasMany(LabAntibiotic::class, 'family_id')->orderBy('name');
    }

    /** Une famille dont une bactérie a servi dans un antibiogramme ne se supprime jamais. */
    public function isForceDeleteProtected(): bool
    {
        return $this->bacteria()->withTrashed()->exists() || $this->antibiotics()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
