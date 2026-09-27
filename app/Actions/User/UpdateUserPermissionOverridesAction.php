<?php

namespace App\Actions\User;

use App\Models\Permission;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Les exceptions individuelles d'un compte, et rien d'autre (ADR-100).
 *
 * Jusqu'ici elles ne se modifiaient qu'en renvoyant la fiche entière du
 * compte — nom, e-mail, rôle, profil — depuis le formulaire utilisateur.
 * Les exceptions ayant rejoint l'écran « Rôles & permissions », cet écran
 * devait sinon réexpédier une identité qu'il ne modifie pas : la moindre
 * divergence l'aurait réécrite sans que personne ne l'ait demandé.
 *
 * Le contrat reste celui de l'ADR-033 : seules les lignes `MANUAL` sont
 * remplacées, les lignes `PROFILE` d'un profil métier ne sont pas touchées
 * ici, et un `DENY` individuel reste prioritaire sur tout le reste.
 */
class UpdateUserPermissionOverridesAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
        private readonly SyncProfessionalProfilePermissionsAction $syncPermissions,
    ) {}

    /** @param array<int, array{permission_id: int, effect: string}> $overrides */
    public function execute(User $user, array $overrides, User|CatalogActor $actor): User
    {
        if ($actor->cannot('permissions.assign')) {
            throw new AuthorizationException('Vous n’êtes pas autorisé à attribuer des permissions individuelles.');
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($user, $overrides, $actor, $actorUser) {
            $user = User::query()->with(['professionalProfile', 'permissions'])->lockForUpdate()->findOrFail($user->id);

            $this->guard->assertCanManageTarget($actor, $user);
            $this->guard->assertCanChangeOwnPermissions($actor, $user);

            $before = $this->snapshot($user);

            // Profil inchangé et aucune recommandation appliquée : seul le
            // bloc « MANUAL » de la synchronisation partagée s'exécute.
            $this->syncPermissions->execute(
                $user,
                $user->professionalProfile,
                $user->professionalProfile,
                $overrides,
                false,
            );

            $user->load('permissions');
            $after = $this->snapshot($user);

            if ($before !== $after) {
                $this->auditor->record(
                    'user.permissions.assign',
                    entity: $user,
                    newValues: ['overrides' => $after],
                    oldValues: ['overrides' => $before],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            return $user->load(['role', 'professionalProfile', 'permissions']);
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshot(User $user): array
    {
        return $user->permissions->map(fn (Permission $permission) => [
            'permission' => $permission->name,
            'effect' => $permission->pivot->effect,
            'source' => $permission->pivot->source,
            'source_profile_id' => $permission->pivot->source_profile_id,
        ])->sortBy('permission')->values()->all();
    }
}
