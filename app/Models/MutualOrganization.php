<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'active'])]
class MutualOrganization extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $organization): void {
            $organization->name = Str::squish($organization->name);
            $organization->normalized_name = self::normalize($organization->name);
        });
    }

    public static function normalize(string $name): string
    {
        return Str::lower(Str::ascii(Str::squish($name)));
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(PatientMutualCoverage::class);
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->coverages()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
