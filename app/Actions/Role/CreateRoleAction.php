<?php

namespace App\Actions\Role;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crée un rôle dans la base du site (ADR-100).
 *
 * Le socle est fourni explicitement, jamais recopié en sous-main depuis un
 * autre rôle : l'écran peut proposer « partir de MEDICINE », mais ce qui
 * arrive ici est la liste réellement cochée. Un rôle créé avec un socle vide
 * reste légitime — un compte qui n'a que des exceptions individuelles
 * (ADR-033) est un cas prévu.
 */
class CreateRoleAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array{code: string, name: string, permission_ids?: array<int, int>} $data */
    public function execute(array $data, User|CatalogActor $actor): Role
    {
        if ($actor->cannot('roles.create')) {
            throw new AuthorizationException('Vous ne pouvez pas créer un rôle.');
        }

        $code = RoleCode::normalize($data['code']);

        if ($code === Role::PROTECTED_CODE) {
            throw ValidationException::withMessages([
                'code' => 'Le rôle Super Admin est défini par l’architecture du portail : il ne se crée pas ici (ADR-025, ADR-027).',
            ]);
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($code, $data, $actorUser) {
            // Un code déjà pris par un rôle *archivé* ne se réutilise pas :
            // les deux rôles auraient la même identité dans les seeders de
            // socle et dans l'historique d'audit. On restaure, on ne recrée pas.
            $existing = Role::query()->withTrashed()->where('code', $code)->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'code' => $existing->trashed()
                        ? "Le code « {$code} » appartient à un rôle archivé. Restaurez-le plutôt que d’en créer un second."
                        : "Le code « {$code} » est déjà utilisé.",
                ]);
            }

            $role = Role::query()->create(['code' => $code, 'name' => trim($data['name'])]);

            $permissionIds = Permission::query()
                ->whereIn('id', collect($data['permission_ids'] ?? [])->unique()->values())
                ->pluck('id');

            $role->permissions()->sync($permissionIds);

            $this->auditor->record(
                'role.create',
                entity: $role,
                newValues: [
                    'code' => $role->code,
                    'name' => $role->name,
                    'permissions' => $role->permissions()->pluck('permissions.name')->sort()->values()->all(),
                ],
                module: 'administration',
                actor: $actorUser,
            );

            return $role->load('permissions');
        });
    }
}
