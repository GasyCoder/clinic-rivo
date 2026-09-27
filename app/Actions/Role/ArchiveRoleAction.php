<?php

namespace App\Actions\Role;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Archive un rôle — Soft Delete motivé, jamais une suppression (ADR-009/010).
 *
 * Le refus central : un rôle encore porté par un compte ne s'archive pas.
 * Tout compte actif doit posséder un rôle valide (ADR-022), et la relation
 * `User::role()` ne renvoie plus un rôle archivé — l'archiver sous les pieds
 * de ses titulaires les priverait silencieusement de tout leur socle. Les
 * comptes désactivés comptent aussi : ils peuvent être réactivés.
 */
class ArchiveRoleAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(Role $role, string $reason, User|CatalogActor $actor): Role
    {
        if ($actor->cannot('roles.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver un rôle.');
        }

        if ($role->isProtected()) {
            throw ValidationException::withMessages([
                'reason' => 'Le rôle Super Admin est défini par l’architecture du portail et ne s’archive pas.',
            ]);
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($role, $reason, $actorUser) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            $holders = $role->users()->count();

            if ($holders > 0) {
                throw ValidationException::withMessages([
                    'reason' => "Ce rôle est encore porté par {$holders} compte(s). Réaffectez-les à un autre rôle avant de l’archiver.",
                ]);
            }

            $role->forceFill([
                'deleted_by' => $actorUser?->getKey(),
                'delete_reason' => trim($reason),
            ])->save();

            $role->delete();

            $this->auditor->record(
                'role.archive',
                entity: $role,
                newValues: ['archived' => true, 'reason' => $role->delete_reason],
                oldValues: ['archived' => false],
                module: 'administration',
                actor: $actorUser,
            );

            return $role;
        });
    }
}
