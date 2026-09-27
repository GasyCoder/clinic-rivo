<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-197 / ADR-202 — les accès que le Super Admin a créés pour le personnel
 * d'un site, et qu'il annonce au RH.
 *
 * Aucun mot de passe n'y est gardé : chaque employé choisit le sien à sa première
 * connexion. Une remise se lit donc sur ses employés :
 *
 *   à envoyer          pas encore partie au RH
 *   à rouvrir          au moins un employé a laissé passer le délai de première connexion
 *   en attente         au moins un employé ne s'est pas encore connecté
 *   terminée           tous se sont connectés (ou ne sont plus en poste)
 *
 * Les colonnes des mots de passe d'autrefois (`expires_at`, `purged_at`…) restent
 * pour l'historique ; plus rien ne les écrit.
 */
#[Fillable([
    'uuid', 'created_by_uuid', 'created_by_name', 'sent_at', 'sent_by_uuid', 'sent_by_name',
    'expires_at', 'first_revealed_at', 'first_revealed_by', 'delivered_at', 'delivered_by',
    'purged_at', 'purge_reason',
])]
class StaffAccessHandover extends Model
{
    use HasUuid;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_TO_REOPEN = 'TO_REOPEN';

    public const STATUS_WAITING = 'WAITING';

    public const STATUS_COMPLETE = 'COMPLETE';

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'first_revealed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StaffAccessHandoverItem::class)->orderBy('employee_name');
    }

    /** @param Builder<self> $query */
    public function scopeSent(Builder $query): Builder
    {
        return $query->whereNotNull('sent_at');
    }

    /**
     * Au moins un employé a laissé passer le délai sans se connecter.
     *
     * @param  Builder<self>  $query
     */
    public function scopeToReopen(Builder $query): Builder
    {
        return $query->whereHas('items', fn (Builder $items) => $items->whereHas('user', fn (Builder $user) => $user->activationExpired()));
    }

    /**
     * Au moins un employé peut encore faire sa première connexion.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereHas('items', fn (Builder $items) => $items->whereHas('user', fn (Builder $user) => $user->awaitingActivation()));
    }

    /** L'état, lu sur les employés de la remise (items et leurs comptes chargés). */
    public function status(): string
    {
        if ($this->sent_at === null) {
            return self::STATUS_DRAFT;
        }

        $states = $this->items->map(fn (StaffAccessHandoverItem $item) => $item->activationState());

        return match (true) {
            $states->contains(StaffAccessHandoverItem::STATE_EXPIRED) => self::STATUS_TO_REOPEN,
            $states->contains(StaffAccessHandoverItem::STATE_WAITING) => self::STATUS_WAITING,
            default => self::STATUS_COMPLETE,
        };
    }

    public static function statusLabel(string $status): string
    {
        return [
            self::STATUS_DRAFT => 'À envoyer au RH',
            self::STATUS_TO_REOPEN => 'Délai dépassé',
            self::STATUS_WAITING => 'En attente de connexion',
            self::STATUS_COMPLETE => 'Tous connectés',
        ][$status] ?? $status;
    }
}
