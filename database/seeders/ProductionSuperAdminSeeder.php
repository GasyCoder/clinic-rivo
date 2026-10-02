<?php

namespace Database\Seeders;

use App\Actions\User\BootstrapSuperAdminAction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

/**
 * The first Super Administrator of a freshly installed portal (admin.rivo.mg).
 *
 * `migrate --seed` already gives the portal its roles, permissions, profiles
 * and reference lists. What it could not give is the account to log in with:
 * the demo `superadmin@rivo.test` is local only (ADR-022). This seeder reads it
 * from the deployment's .env — RIVO_SUPER_ADMIN_EMAIL / _NAME / _PASSWORD —
 * so no password is ever written in the code.
 *
 *   - portal only: a clinic site never holds a Super Admin (ADR-027);
 *   - once only: as soon as an active Super Admin exists, nothing is touched,
 *     so re-running db:seed never resets a password or revives an account;
 *   - same rules as `rivo:provision-super-admin` (password policy, audit).
 *
 * Once the account is created, remove RIVO_SUPER_ADMIN_PASSWORD from .env.
 */
class ProductionSuperAdminSeeder extends Seeder
{
    public function run(BootstrapSuperAdminAction $bootstrap): void
    {
        if (config('rivo.site.type') !== 'admin') {
            return;
        }

        $hasActiveSuperAdmin = User::query()
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('code', 'SUPER_ADMIN'))
            ->exists();

        if ($hasActiveSuperAdmin) {
            return;
        }

        $email = trim((string) config('rivo.bootstrap_super_admin.email'));
        $password = (string) config('rivo.bootstrap_super_admin.password');

        if ($email === '' || $password === '') {
            // Locally, DevelopmentTestAccountSeeder gives superadmin@rivo.test next.
            if (app()->environment('local', 'testing')) {
                return;
            }

            $this->command?->warn('Aucun Super Administrateur sur ce portail : renseignez RIVO_SUPER_ADMIN_EMAIL, RIVO_SUPER_ADMIN_NAME et RIVO_SUPER_ADMIN_PASSWORD puis relancez `php artisan db:seed --force`, ou utilisez `php artisan rivo:provision-super-admin`.');

            return;
        }

        $name = trim((string) config('rivo.bootstrap_super_admin.name')) ?: 'Super Administrateur';

        try {
            $user = $bootstrap->execute($name, $email, $password, via: 'seeder');
        } catch (ValidationException $exception) {
            $this->command?->error('Super Administrateur non créé : '.implode(' ', $exception->validator->errors()->all()));

            return;
        }

        $this->command?->info("Super Administrateur {$user->email} créé. Retirez RIVO_SUPER_ADMIN_PASSWORD du .env.");
    }
}
