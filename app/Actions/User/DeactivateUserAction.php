<?php

namespace App\Actions\User;

use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateUserAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
    ) {}

    public function execute(User $user, string $reason, User $actor): User
    {
        if ($actor->cannot('users.deactivate')) {
            throw new AuthorizationException('Vous ne pouvez pas désactiver un utilisateur.');
        }

        return DB::transaction(function () use ($user, $reason, $actor) {
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
                'deactivated_by' => $actor->id,
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
                actor: $actor,
            );

            return $user->load('deactivator');
        });
    }
}
