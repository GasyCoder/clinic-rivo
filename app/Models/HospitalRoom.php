<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ADR-164 — une chambre d'un service. Créée avec son nombre de lits, qui
 * reçoivent d'office les noms « Lit 1 » à « Lit N » ; chacun se renomme ou se
 * retire ensuite. Archiver une chambre retire ses lits de toute attribution
 * sans les toucher : la restaurer les rend tels qu'ils étaient.
 */
#[Fillable(['hospital_service_id', 'name'])]
class HospitalRoom extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $room): void {
            $room->name = Str::squish($room->name);
            $room->normalized_name = HospitalService::normalize($room->name);
        });
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(HospitalService::class, 'hospital_service_id')->withTrashed();
    }

    public function beds(): HasMany
    {
        return $this->hasMany(HospitalBed::class);
    }

    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'hospitalization';
    }
}
