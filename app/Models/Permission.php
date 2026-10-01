<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Fillable(['name', 'label'])]
class Permission extends Model
{
    public const CACHE_KEY = 'rivo.permissions.names';

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetNames());
        static::deleted(fn () => self::forgetNames());
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions')
            ->withPivot('effect', 'source', 'source_profile_id')
            ->withTimestamps();
    }

    /**
     * All known permission names, cached until a permission is created/updated/deleted.
     *
     * Cached as a plain array, never a Collection: the database cache store
     * refuses to unserialize PHP objects by default (config/cache.php
     * `serializable_classes`), a deliberate guard against gadget-chain
     * attacks if APP_KEY leaks — do not weaken that to cache objects here.
     */
    public static function allNames(): Collection
    {
        // Lu à chaque `can()` : sans mémoire de requête, le cache stocké en base
        // coûtait une requête SQL par vérification de droit (47 sur la vue
        // d'ensemble). `memo()` ne relit le cache qu'une fois par requête.
        return collect(Cache::memo()->rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->pluck('name')->all()
        ));
    }

    /**
     * Forget the cached names, in the request memo as well as in the store.
     */
    public static function forgetNames(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
    }
}
