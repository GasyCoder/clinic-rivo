<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** ADR-213 — un germe identifiable en culture, rangé dans sa famille. */
#[Fillable(['family_id', 'name', 'is_active', 'created_by', 'updated_by'])]
class LabBacterium extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected $table = 'lab_bacteria';

    protected static function booted(): void
    {
        static::saving(function (self $bacterium): void {
            $bacterium->name = Str::squish((string) $bacterium->name);
            $bacterium->normalized_name = LabBacteriumFamily::normalize($bacterium->name);
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
        return LabAntibiogram::query()->where('bacterium_id', $this->getKey())->exists();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
