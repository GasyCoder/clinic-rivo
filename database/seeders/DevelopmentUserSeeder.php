<?php

namespace Database\Seeders;

use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\SecurePassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;
use RuntimeException;

class DevelopmentUserSeeder extends Seeder
{
    /**
     * Development fixtures. Profiles receive their recommended permissions
     * as explicit per-account ALLOW overrides; profiles never authorize by
     * themselves (ADR-033).
     *
     * @var array<int, array{key: string, name: string, role: string, profile?: string}>
     */
    private const CLINIC_ACCOUNTS = [
        ['key' => 'administration', 'name' => 'Compte test Administration', 'role' => 'ADMINISTRATION'],
        ['key' => 'logistique', 'name' => 'Compte test Logistique', 'role' => 'LOGISTICS'],
        ['key' => 'gardien', 'name' => 'Compte test Gardien', 'role' => 'SUPPORT', 'profile' => 'GUARD'],
        ['key' => 'entretien', 'name' => 'Compte test Femme de ménage', 'role' => 'SUPPORT', 'profile' => 'CLEANER'],
        ['key' => 'technicien-informatique', 'name' => 'Compte test Technicien informatique', 'role' => 'MAINTENANCE', 'profile' => 'IT_TECHNICIAN'],
        ['key' => 'reception', 'name' => 'Compte test Réception', 'role' => 'RECEPTION'],
        ['key' => 'medecin', 'name' => 'Compte test Médecin', 'role' => 'MEDICINE'],
        ['key' => 'infirmiere', 'name' => 'Compte test Infirmière', 'role' => 'NURSE', 'profile' => 'REGISTERED_NURSE'],
        ['key' => 'sage-femme', 'name' => 'Compte test Sage-femme', 'role' => 'NURSE', 'profile' => 'MIDWIFE'],
        ['key' => 'anesthesiste', 'name' => 'Compte test Anesthésiste', 'role' => 'NURSE', 'profile' => 'ANESTHETIST'],
        ['key' => 'chirurgien', 'name' => 'Compte test Chirurgien', 'role' => 'SURGERY'],
        ['key' => 'pharmacie', 'name' => 'Compte test Pharmacie', 'role' => 'PHARMACY'],
        ['key' => 'laboratoire', 'name' => 'Compte test Laboratoire', 'role' => 'LABORATORY'],
    ];

    /** @var array<int, array{key: string, name: string, role: string}> */
    private const ADMIN_ACCOUNTS = [
        ['key' => 'superadmin', 'name' => 'Compte test Super Administration', 'role' => 'SUPER_ADMIN'],
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException(
                'DevelopmentUserSeeder est strictement interdit hors des environnements local et testing.',
            );
        }

        $deploymentType = config('rivo.site.type');
        $definitions = match ($deploymentType) {
            'clinic' => self::CLINIC_ACCOUNTS,
            'admin' => self::ADMIN_ACCOUNTS,
            default => throw new LogicException(
                'DevelopmentUserSeeder est disponible uniquement sur un déploiement clinic ou admin.',
            ),
        };

        $siteCode = mb_strtolower(trim((string) config('rivo.site.code')));
        $siteName = trim((string) config('rivo.site.name'));

        if ($siteCode === '' || $siteName === '') {
            throw new RuntimeException(
                'RIVO_SITE_CODE et RIVO_SITE_NAME sont obligatoires pour créer les comptes de développement.',
            );
        }

        $password = (string) config('rivo.seeders.development_users_password');
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', SecurePassword::rule()]],
        );

        if ($validator->fails()) {
            throw new RuntimeException(
                'RIVO_DEVELOPMENT_USERS_PASSWORD doit contenir au moins 12 caractères, '.
                'avec majuscule, minuscule, chiffre et symbole.',
            );
        }

        $auditor = app(Auditor::class);
        $rows = [];

        DB::transaction(function () use ($definitions, $siteCode, $siteName, $password, $auditor, &$rows): void {
            foreach ($definitions as $definition) {
                $role = Role::query()->where('code', $definition['role'])->first();

                if (! $role) {
                    throw new RuntimeException(
                        "Le rôle {$definition['role']} est absent. Exécutez d'abord les seeders RBAC.",
                    );
                }

                $profile = $this->resolveProfile($role, $definition['profile'] ?? null);
                $email = "{$definition['key']}.{$siteCode}@rivo.test";
                $user = User::query()->where('email', $email)->first() ?? new User;
                $wasCreated = ! $user->exists;
                $oldValues = $user->exists ? [
                    'name' => $user->name,
                    'role' => $user->role?->code,
                    'professional_profile' => $user->professionalProfile?->code,
                    'active' => $user->isActive(),
                ] : [];

                $user->forceFill([
                    'name' => "{$definition['name']} — {$siteName}",
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => $password,
                    'role_id' => $role->id,
                    'professional_profile_id' => $profile?->id,
                    'active' => true,
                    'deactivated_by' => null,
                    'deactivated_at' => null,
                    'deactivation_reason' => null,
                    'remember_token' => null,
                ])->save();

                $recommendedPermissions = $profile
                    ? $profile->recommendedPermissions()->get(['permissions.id', 'permissions.name'])
                    : collect();
                $user->permissions()->sync(
                    $recommendedPermissions->mapWithKeys(fn ($permission) => [
                        $permission->id => ['effect' => 'allow'],
                    ])->all(),
                );

                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();

                $newValues = [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role->code,
                    'professional_profile' => $profile?->code,
                    'permissions' => $recommendedPermissions->pluck('name')->sort()->values()->all(),
                    'active' => true,
                    'development_fixture' => true,
                ];

                $auditor->record(
                    $wasCreated ? 'user.development_seed.create' : 'user.development_seed.reset',
                    entity: $user,
                    newValues: $newValues,
                    oldValues: $oldValues,
                    reason: 'Compte local de test créé explicitement pour le développement.',
                    module: 'administration',
                );

                $rows[] = [
                    $user->name,
                    $user->email,
                    $role->name,
                    $profile?->name ?? '—',
                ];
            }
        });

        $this->command?->table(['Compte', 'Email', 'Rôle', 'Profil'], $rows);
        $this->command?->warn(
            'Mot de passe local commun : '.config('rivo.seeders.development_users_password'),
        );
        $this->command?->warn('Ces comptes sont interdits en production et sont réinitialisés à chaque exécution.');
    }

    private function resolveProfile(Role $role, ?string $profileCode): ?ProfessionalProfile
    {
        if (! $profileCode) {
            if ($role->professionalProfiles()->active()->exists()) {
                throw new RuntimeException("Le rôle {$role->code} exige un profil professionnel.");
            }

            return null;
        }

        $profile = ProfessionalProfile::query()
            ->active()
            ->where('role_id', $role->id)
            ->where('code', $profileCode)
            ->first();

        if (! $profile) {
            throw new RuntimeException(
                "Le profil {$profileCode} ne correspond pas au rôle {$role->code}.",
            );
        }

        return $profile;
    }
}
