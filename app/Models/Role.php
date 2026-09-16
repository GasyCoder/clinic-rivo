<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un rôle n'est jamais supprimé physiquement (ADR-009/010) : il a porté les
 * droits de tout compte qui l'a exercé, et son code est référencé par les
 * seeders de socle. « Archiver » le retire des affectations possibles sans
 * effacer cette histoire.
 */
#[Fillable(['code', 'name'])]
class Role extends Model
{
    use SoftDeletes;

    /** Le seul rôle que le portail ne gère jamais (ADR-025, ADR-027). */
    public const PROTECTED_CODE = 'SUPER_ADMIN';

    public function isProtected(): bool
    {
        return $this->code === self::PROTECTED_CODE;
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function professionalProfiles(): HasMany
    {
        return $this->hasMany(ProfessionalProfile::class);
    }
}
