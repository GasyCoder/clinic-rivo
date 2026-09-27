<?php

namespace App\Actions\StaffAccess;

use App\Actions\User\UpdateUserPermissionOverridesAction;
use App\Enums\UserPermissionSource;
use App\Models\Permission;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use Illuminate\Validation\ValidationException;

/**
 * ADR-199 — le Super Admin désigne, depuis le portail, le compte du site qui
 * recevra et remettra les accès du personnel.
 *
 * Les mots de passe ne repassent jamais par le portail (ADR-197) : c'est une
 * personne du site qui les affiche, les imprime et les remet. Quand personne au
 * site n'en a le droit, l'envoi est refusé ; ce geste le règle sans quitter la
 * fenêtre. Il ajoute au compte choisi une exception individuelle `ALLOW` sur
 * `staff_access.receive`, par l'action des exceptions (ADR-100) : ses autres
 * exceptions restent telles quelles, et le changement est audité
 * (`user.permissions.assign`). Il exige `permissions.assign`, comme toute
 * exception individuelle (ADR-022).
 */
class DesignateStaffAccessReceiverAction
{
    public function __construct(private readonly UpdateUserPermissionOverridesAction $overrides) {}

    public function execute(User $user, CatalogActor $actor): User
    {
        if (! $user->active) {
            throw ValidationException::withMessages(['user_uuid' => 'Ce compte est désactivé : choisissez un compte actif du site.']);
        }

        if ($user->hasRole('SUPER_ADMIN')) {
            throw ValidationException::withMessages(['user_uuid' => 'Un compte Super Administrateur ne vit pas sur un site.']);
        }

        if ($user->can(SendStaffAccessHandoverAction::PERMISSION)) {
            return $user;
        }

        $permission = Permission::query()->where('name', SendStaffAccessHandoverAction::PERMISSION)->firstOrFail();

        // Les exceptions manuelles du compte, gardées telles quelles ; un refus
        // sur ce droit précis cède à la désignation, faite nommément par le Super Admin.
        $manual = $user->permissions()
            ->wherePivot('source', UserPermissionSource::Manual->value)
            ->get()
            ->reject(fn (Permission $existing) => $existing->id === $permission->id)
            ->map(fn (Permission $existing) => ['permission_id' => $existing->id, 'effect' => $existing->pivot->effect])
            ->values()
            ->all();
        $manual[] = ['permission_id' => $permission->id, 'effect' => 'allow'];

        $updated = $this->overrides->execute($user, $manual, $actor);

        if (! $updated->fresh()->can(SendStaffAccessHandoverAction::PERMISSION)) {
            throw ValidationException::withMessages(['user_uuid' => 'Ce compte ne peut toujours pas recevoir les accès : vérifiez ses droits dans « Rôles & permissions ».']);
        }

        return $updated;
    }

    /**
     * Les comptes actifs du site qu'on peut désigner : ceux qui reçoivent déjà
     * d'abord, puis les comptes de l'Administration (le RH), puis les autres.
     *
     * @return list<array{uuid: string, name: string, email: string, role: string|null, receives: bool}>
     */
    public static function candidates(): array
    {
        return User::query()
            ->with('role:id,code,name')
            ->where('active', true)
            ->get()
            ->reject(fn (User $user) => $user->hasRole('SUPER_ADMIN'))
            ->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'role_code' => $user->role?->code,
                'receives' => $user->can(SendStaffAccessHandoverAction::PERMISSION),
            ])
            ->sortBy(fn (array $user) => [! $user['receives'], $user['role_code'] !== 'ADMINISTRATION', mb_strtolower($user['name'])])
            ->values()
            ->all();
    }
}
