<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * ADR-197 — une ligne de la table `notifications`, lue par la cloche et la page
 * « Notifications » : celle de Laravel, plus son archivage.
 */
class UserNotification extends DatabaseNotification
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey());
    }
}
