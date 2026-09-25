<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-193 — une maintenance d'un site : son message, sa fenêtre, et qui l'a
 * posée puis levée. Jamais effacée : l'historique dit qui a fermé le site, quand
 * et pourquoi.
 *
 * Sa fenêtre se lit à chaque requête, jamais par un traitement planifié :
 *
 *   à venir     le début n'est pas atteint (bandeau d'avertissement)
 *   en cours    commencée, ni levée ni arrivée à sa fin prévue
 *   terminée    sa fin prévue est passée : le site a rouvert de lui-même
 *   levée       quelqu'un l'a levée (ou annulée avant son début)
 *
 * Une seule à la fois n'est ni levée ni terminée : les actions la verrouillent et
 * la modifient au lieu d'en créer une seconde.
 *
 * Les colonnes ne sont pas auditées par un trait : chaque geste (mise en
 * maintenance, programmation, modification, levée) a sa propre action d'audit.
 */
#[Fillable([
    'title', 'message', 'starts_at', 'ends_at',
    'created_by', 'external_created_by_uuid', 'external_created_by_name',
    'updated_by', 'external_updated_by_uuid', 'external_updated_by_name',
    'lifted_at', 'lifted_by', 'external_lifted_by_uuid', 'external_lifted_by_name', 'lift_reason',
])]
class SiteMaintenance extends Model
{
    use HasUuid;

    public const STATE_UPCOMING = 'UPCOMING';

    public const STATE_ACTIVE = 'ACTIVE';

    public const STATE_ENDED = 'ENDED';

    public const STATE_LIFTED = 'LIFTED';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /** Ni levée, ni arrivée à sa fin prévue : en cours, ou à venir. */
    public function scopeOpen(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query
            ->whereNull('lifted_at')
            ->where(fn (Builder $window) => $window->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /** La maintenance en cours ou à venir, s'il y en a une. */
    public static function current(): ?self
    {
        return static::query()->open()->latest('starts_at')->latest('id')->first();
    }

    public function state(?CarbonInterface $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->lifted_at !== null => self::STATE_LIFTED,
            $this->ends_at !== null && $this->ends_at->lte($now) => self::STATE_ENDED,
            $this->starts_at->gt($now) => self::STATE_UPCOMING,
            default => self::STATE_ACTIVE,
        };
    }

    public function isActive(?CarbonInterface $now = null): bool
    {
        return $this->state($now) === self::STATE_ACTIVE;
    }

    public function isUpcoming(?CarbonInterface $now = null): bool
    {
        return $this->state($now) === self::STATE_UPCOMING;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    /** Le nom de qui l'a posée : le Super Administrateur distant, ou un compte du site. */
    public function createdByName(): ?string
    {
        return $this->external_created_by_name ?? $this->creator?->name;
    }

    public function liftedByName(): ?string
    {
        return $this->external_lifted_by_name ?? $this->lifter?->name;
    }
}
