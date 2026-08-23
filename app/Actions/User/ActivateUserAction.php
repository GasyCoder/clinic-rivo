<?php

namespace App\Actions\User;

use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateUserAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
    ) {}

    public function execute(User $user, User $actor): User
    {
        if ($actor->cannot('users.activate')) {
            throw new AuthorizationException('Vous ne pouvez pas réactiver un utilisateur.');
        }

        return DB::transaction(function () use ($user, $actor) {
            $user = User::query()->with('role')->lockForUpdate()->findOrFail($user->id);
            $this->guard->assertCanManageTarget($actor, $user);

            if ($user->isActive()) {
                throw ValidationException::withMessages([
                    'user' => 'Ce compte est déjà actif.',
                ]);
            }

            if (! $user->role) {
                throw ValidationException::withMessages([
                    'role_id' => 'Attribuez un rôle valide avant de réactiver ce compte.',
                ]);
            }

            $user->forceFill([
                'active' => true,
                'deactivated_by' => null,
                'deactivated_at' => null,
                'deactivation_reason' => null,
            ])->save();

            $this->auditor->record(
                'user.activate',
                entity: $user,
                newValues: ['active' => true],
                oldValues: ['active' => false],
                module: 'administration',
                actor: $actor,
            );

            return $user;
        });
    }
}
