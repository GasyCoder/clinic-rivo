<?php

namespace App\Services\Authorization;

use App\Models\User;

/**
 * Separates central identities from operational site identities.
 *
 * Permissions still authorize each action. This policy is an additional
 * deployment boundary: a central SUPER_ADMIN identity is never a valid
 * operational identity on a clinic deployment, and an operational identity
 * is never a valid portal identity on the admin deployment.
 */
class DeploymentAccountPolicy
{
    public function allows(User $user, ?string $deploymentType = null): bool
    {
        $deploymentType ??= config('rivo.site.type');

        if (! $user->isActive() || ! $user->role_id || ! $user->role()->exists()) {
            return false;
        }

        return match ($deploymentType) {
            'admin' => $user->hasRole('SUPER_ADMIN')
                && $user->hasPermissionTo('super_admin.portal.view'),
            'clinic' => ! $user->hasRole('SUPER_ADMIN'),
            default => false,
        };
    }

    public function denialMessage(?string $deploymentType = null): string
    {
        return match ($deploymentType ?? config('rivo.site.type')) {
            'admin' => 'Ce portail est réservé aux comptes Super Administration.',
            'clinic' => 'Un compte Super Administration ne peut pas se connecter directement à un site. Utilisez un compte opérationnel propre à ce site.',
            default => 'Ce compte n’est pas autorisé sur ce déploiement.',
        };
    }
}
