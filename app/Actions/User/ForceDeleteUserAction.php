<?php

namespace App\Actions\User;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-062's narrow, deliberate exception to ADR-022: physical deletion is
 * possible only for an account with zero historical footprint — never used
 * to sign in, and never the actor behind any audited action anywhere. Any
 * account with real activity keeps only the deactivation path.
 */
class ForceDeleteUserAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
    ) {}

    public function execute(User $user, User|CatalogActor $actor): void
    {
        if ($actor->cannot('users.force_delete')) {
            throw new AuthorizationException('Vous ne pouvez pas supprimer définitivement un compte.');
        }

        // Same resolution as the other User actions: Auditor::record() takes
        // an Authenticatable, never a CatalogActor — a remote Super Admin
        // resolves to null and Auditor falls back to the request's own
        // external_actor_uuid/name.
        $actorUser = $actor instanceof User ? $actor : $actor->user();

        DB::transaction(function () use ($user, $actor, $actorUser) {
            $user = User::query()->with('role')->lockForUpdate()->findOrFail($user->id);

            if ($actor instanceof User && $actor->is($user)) {
                throw ValidationException::withMessages([
                    'user' => 'Vous ne pouvez pas supprimer votre propre compte.',
                ]);
            }

            $this->guard->assertCanManageTarget($actor, $user);
            $this->guard->assertLastActiveSuperAdminPreserved($user);

            if ($user->last_login_at !== null || AuditLog::query()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'user' => 'Ce compte a déjà servi ou a un historique tracé — seule la désactivation reste possible, pour préserver les traces d’audit.',
                ]);
            }

            $snapshot = [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->code,
                'uuid' => $user->uuid,
            ];

            try {
                User::allowPhysicalDeletion(fn () => $user->delete());
            } catch (QueryException $exception) {
                throw ValidationException::withMessages([
                    'user' => 'Ce compte est référencé par des données cliniques, financières ou administratives et ne peut pas être supprimé — désactivez-le à la place.',
                ]);
            }

            $this->auditor->record(
                'user.force_delete',
                newValues: $snapshot,
                module: 'administration',
                actor: $actorUser,
            );
        });
    }
}
