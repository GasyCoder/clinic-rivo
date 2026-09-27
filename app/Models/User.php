<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Builders\ProtectedUserBuilder;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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
    'professional_profile_id',
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
            // ADR-202 — première connexion : ouverte jusqu'à `activation_open_until`, faite à `activated_at`.
            'activation_open_until' => 'datetime',
            'activated_at' => 'datetime',
            // ADR-191 — taille du texte, animations, contraste choisis dans « Mon profil ».
            'ui_preferences' => 'array',
        ];
    }

    /**
     * Off by default — every ordinary code path stays blocked. Set only for
     * the duration of ForceDeleteUserAction's own sanctioned delete call, so
     * an accidental ->delete() anywhere else in the codebase still throws.
     */
    private static bool $physicalDeletionAllowed = false;

    public static function allowPhysicalDeletion(\Closure $callback): mixed
    {
        static::$physicalDeletionAllowed = true;

        try {
            return $callback();
        } finally {
            static::$physicalDeletionAllowed = false;
        }
    }

    public static function physicalDeletionAllowed(): bool
    {
        return static::$physicalDeletionAllowed;
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            if (! static::physicalDeletionAllowed()) {
                throw new LogicException('User accounts cannot be deleted. Deactivate the account instead.');
            }
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

    public function professionalProfile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class);
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
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('effect', 'source', 'source_profile_id')
            ->withTimestamps();
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
     * ADR-202 — le compte attend sa première connexion : personne n'a encore choisi
     * son mot de passe, et le délai n'est pas passé. Seul ce cas ouvre, à la connexion,
     * « Bonjour …, choisissez votre mot de passe » après la seule adresse email.
     */
    public function awaitsActivation(): bool
    {
        return $this->isActive()
            && $this->activated_at === null
            && $this->activation_open_until !== null
            && $this->activation_open_until->isFuture();
    }

    /**
     * ADR-202 — les comptes qui peuvent encore faire leur première connexion.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAwaitingActivation(Builder $query): void
    {
        $this->constrainNeverActivated($query);
        $query->where('activation_open_until', '>', now());
    }

    /**
     * ADR-202 — les comptes qui ont laissé passer le délai sans se connecter : à rouvrir.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActivationExpired(Builder $query): void
    {
        $this->constrainNeverActivated($query);
        $query->where(fn (Builder $window) => $window->whereNull('activation_open_until')->orWhere('activation_open_until', '<=', now()));
    }

    /** @param  Builder<User>  $query */
    private function constrainNeverActivated(Builder $query): void
    {
        $query->where('active', true)->whereNull('deactivated_at')->whereNull('activated_at')->whereNull('last_login_at');
    }

    /** La première connexion est faite : mot de passe choisi, ou première connexion réussie. */
    public function markActivated(): void
    {
        if ($this->activated_at === null || $this->activation_open_until !== null) {
            $this->forceFill(['activated_at' => $this->activated_at ?? now(), 'activation_open_until' => null])->saveQuietly();
        }
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
