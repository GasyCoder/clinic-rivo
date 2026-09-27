<?php

namespace App\Models;

use App\Enums\HospitalCareLevel;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

/**
 * ADR-164 — un service d'hospitalisation du site (Médecine interne,
 * Réanimation…). Il porte le niveau de soins de ses lits : installer un
 * patient dans un lit de réanimation met le niveau du séjour à jour, sans
 * seconde saisie qui pourrait le contredire.
 */
#[Fillable(['name', 'care_level'])]
class HospitalService extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $service): void {
            $service->name = Str::squish($service->name);
            $service->normalized_name = self::normalize($service->name);
        });
    }

    public static function normalize(string $name): string
    {
        return Str::lower(Str::ascii(Str::squish($name)));
    }

    protected function casts(): array
    {
        return ['care_level' => HospitalCareLevel::class];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HospitalRoom::class);
    }

    public function beds(): HasManyThrough
    {
        return $this->hasManyThrough(HospitalBed::class, HospitalRoom::class);
    }

    /** Des séjours l'ont traversé : il s'archive, il ne se détruit jamais. */
    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'hospitalization';
    }
}
