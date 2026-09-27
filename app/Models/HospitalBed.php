<?php

namespace App\Models;

use App\Enums\HospitalBedState;
use App\Enums\HospitalStayStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * ADR-164 — un lit.
 *
 * Il est **occupé** parce qu'un séjour en cours le porte, **hors service**
 * parce que quelqu'un l'a déclaré tel, **libre** sinon. L'occupation n'est
 * jamais saisie : elle se lit sur les séjours, et l'index unique de
 * `hospital_stays.bed_active_key` empêche deux séjours en cours sur le même lit.
 */
#[Fillable([
    'hospital_room_id', 'label',
    'out_of_service_at', 'out_of_service_reason', 'out_of_service_by',
    'external_out_of_service_by_uuid', 'external_out_of_service_by_name',
])]
class HospitalBed extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $bed): void {
            $bed->label = Str::squish($bed->label);
            $bed->normalized_label = HospitalService::normalize($bed->label);
        });
    }

    protected function casts(): array
    {
        return ['out_of_service_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HospitalRoom::class, 'hospital_room_id')->withTrashed();
    }

    /** Le séjour en cours dans ce lit, s'il y en a un. */
    public function activeStay(): HasOne
    {
        return $this->hasOne(HospitalStay::class)->where('status', HospitalStayStatus::Active->value);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(HospitalStay::class);
    }

    /**
     * Les lits que l'on peut réellement attribuer : ni archivés, ni dans une
     * chambre ou un service archivé. L'occupation et le hors-service se
     * décident ensuite, lit par lit.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInUse(Builder $query): void
    {
        $query->whereHas('room', fn (Builder $room) => $room
            ->whereNull('hospital_rooms.deleted_at')
            ->whereHas('service', fn (Builder $service) => $service->whereNull('hospital_services.deleted_at')));
    }

    public function state(): HospitalBedState
    {
        return match (true) {
            $this->relationLoaded('activeStay') ? $this->activeStay !== null : $this->activeStay()->exists() => HospitalBedState::Occupied,
            $this->out_of_service_at !== null => HospitalBedState::OutOfService,
            default => HospitalBedState::Free,
        };
    }

    /** « Chambre 12 · Lit 2 » : l'instantané écrit sur le séjour. */
    public function locationLabel(): string
    {
        return trim(($this->room?->name ?? '').' · '.$this->label, ' ·');
    }

    /** Des séjours l'ont occupé : il s'archive, il ne se détruit jamais. */
    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'hospitalization';
    }
}
