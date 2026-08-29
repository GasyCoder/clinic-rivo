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
 * A commercial/institutional partner (ISPSG, TsaraShop…) — distinct from
 * MutualOrganization: coverage is scoped to specific prestations, never a
 * percentage of the whole tariff grid. See EpisodeFinancialMode::Partner.
 */
#[Fillable(['name', 'active'])]
class PartnerOrganization extends Model
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

    public function episodeCoverages(): HasMany
    {
        return $this->hasMany(EpisodePartnerCoverage::class);
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->episodeCoverages()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
