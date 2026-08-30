<?php

namespace App\Actions\User;

use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateUserAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
    ) {}

    public function execute(User $user, string $reason, User|CatalogActor $actor): User
    {
        if ($actor->cannot('users.deactivate')) {
            throw new AuthorizationException('Vous ne pouvez pas désactiver un utilisateur.');
        }

        // Auditor::record() takes an Authenticatable, never a CatalogActor —
        // for a remote Super Admin this resolves to null, and Auditor falls
        // back to the external_actor_uuid/name already on the request.
        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($user, $reason, $actor, $actorUser) {
            $user = User::query()->with('role')->lockForUpdate()->findOrFail($user->id);

            $this->guard->assertCanManageTarget($actor, $user);
            $this->guard->assertNotSelfDeactivation($actor, $user);
            $this->guard->assertLastActiveSuperAdminPreserved($user);

            if (! $user->isActive()) {
                throw ValidationException::withMessages([
                    'user' => 'Ce compte est déjà désactivé.',
                ]);
            }

            $user->forceFill([
                'active' => false,
                'deactivated_by' => $actor instanceof User ? $actor->id : null,
                ...($actor instanceof CatalogActor ? $actor->externalAttribution('deactivated') : []),
                'deactivated_at' => now(),
                'deactivation_reason' => $reason,
                'remember_token' => null,
            ])->save();

            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();

            $this->auditor->record(
                'user.deactivate',
                entity: $user,
                newValues: ['active' => false],
                oldValues: ['active' => true],
                reason: $reason,
                module: 'administration',
                actor: $actorUser,
            );

            return $user->load('deactivator');
        });
    }
}
