<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Builders\ProtectedUserBuilder;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use LogicException;

#[Fillable([
    'name',
    'email',
    'password',
    'role_id',
    'email_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuid, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('User accounts cannot be deleted. Deactivate the account instead.');
        });
    }

    public function newEloquentBuilder($query): ProtectedUserBuilder
    {
        return new ProtectedUserBuilder($query);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deactivated_by');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Permissions explicitly granted or denied to this user, overriding their role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')->withPivot('effect');
    }

    public function hasRole(string $code): bool
    {
        return $this->role?->code === $code;
    }

    public function isActive(): bool
    {
        return $this->active && $this->deactivated_at === null;
    }

    /**
     * The full set of permission names this user currently holds, resolved as:
     * explicit user DENY > explicit user ALLOW > role permission > deny by default.
     *
     * Memoized per object instance via once(): correct for the normal request
     * lifecycle (one $request->user() instance, checked from several places),
     * but a role/permission change made through *this same* instance won't be
     * reflected without re-fetching a fresh instance — refresh() is not enough.
     */
    public function effectivePermissionNames(): Collection
    {
        return once(function () {
            $allowed = $this->permissions()->wherePivot('effect', 'allow')->pluck('permissions.name');
            $denied = $this->permissions()->wherePivot('effect', 'deny')->pluck('permissions.name');

            $rolePermissions = $this->role
                ? $this->role->permissions()->pluck('permissions.name')
                : collect();

            return $rolePermissions->merge($allowed)->unique()->diff($denied)->values();
        });
    }

    public function hasPermissionTo(string $permission): bool
    {
        return $this->effectivePermissionNames()->contains($permission);
    }
}
