<?php

namespace App\Actions\User;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\SecurePassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Creates the first real Super Administrator of the central portal (ADR-022).
 *
 * One path for both entry points: the interactive `rivo:provision-super-admin`
 * command and the production seeder that reads RIVO_SUPER_ADMIN_* — so the
 * password policy, the uniqueness check and the audit trail never diverge.
 */
class BootstrapSuperAdminAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @throws ValidationException when the name, email or password is refused
     * @throws LogicException on a clinic deployment or without the SUPER_ADMIN role
     */
    public function execute(string $name, string $email, string $password, ?string $confirmation = null, string $via = 'command'): User
    {
        if (config('rivo.site.type') !== 'admin') {
            throw new LogicException('Un Super Administrateur ne se crée que sur le portail central (RIVO_SITE_TYPE=admin).');
        }

        $role = Role::query()->where('code', 'SUPER_ADMIN')->first();

        if (! $role) {
            throw new LogicException('Le rôle SUPER_ADMIN est absent. Exécutez les seeders RBAC avant.');
        }

        $name = trim($name);
        $email = mb_strtolower(trim($email));

        Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', SecurePassword::rule()],
        ])->validate();

        return DB::transaction(function () use ($email, $name, $password, $role, $via) {
            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role_id' => $role->id,
                'email_verified_at' => now(),
            ]);
            $user->forceFill(['active' => true])->save();

            $this->auditor->record(
                'user.bootstrap_super_admin',
                entity: $user,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'SUPER_ADMIN',
                    'active' => true,
                    'via' => $via,
                ],
                module: 'administration',
            );

            $this->auditor->record(
                'user.role.assign',
                entity: $user,
                newValues: ['role' => 'SUPER_ADMIN'],
                module: 'administration',
            );

            return $user;
        });
    }
}
