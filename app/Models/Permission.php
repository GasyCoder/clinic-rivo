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
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions')->withPivot('effect');
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
        return collect(Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->pluck('name')->all()
        ));
    }
}
