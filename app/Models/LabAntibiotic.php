<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** ADR-213 — un antibiotique testé sur les germes d'une famille. */
#[Fillable(['family_id', 'name', 'comment', 'is_active', 'created_by', 'updated_by'])]
class LabAntibiotic extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $antibiotic): void {
            $antibiotic->name = Str::squish((string) $antibiotic->name);
            $antibiotic->normalized_name = LabBacteriumFamily::normalize($antibiotic->name);
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(LabBacteriumFamily::class, 'family_id')->withTrashed();
    }

    public function isForceDeleteProtected(): bool
    {
        return LabAntibiogramResult::query()->where('antibiotic_id', $this->getKey())->exists();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
