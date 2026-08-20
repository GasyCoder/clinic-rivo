<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\SecurePassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProvisionSuperAdmin extends Command
{
    /** @var string */
    protected $signature = 'rivo:provision-super-admin
        {email : Adresse email professionnelle unique sur ce site}
        {--name= : Nom complet du responsable}
        {--replace-demo-users : Désactiver les anciens comptes de démonstration après création}';

    /** @var string */
    protected $description = 'Créer de façon sécurisée le premier vrai Super Administrateur du site';

    /** @var array<int, string> */
    private const DEMO_EMAILS = [
        'test@example.com',
        'demo.surgeon@rivo.mg',
        'demo.surgeon2@rivo.mg',
        'demo.nurse@rivo.mg',
        'demo.nurse2@rivo.mg',
    ];

    public function handle(Auditor $auditor): int
    {
        if (! config('rivo.site.code') || ! config('rivo.site.name')) {
            $this->error('RIVO_SITE_CODE et RIVO_SITE_NAME doivent identifier ce site avant le provisionnement.');

            return self::FAILURE;
        }

        $role = Role::query()->where('code', 'SUPER_ADMIN')->first();

        if (! $role) {
            $this->error('Le rôle SUPER_ADMIN est absent. Exécutez les seeders RBAC avant cette commande.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Nom complet')));
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->secret('Mot de passe (12 caractères minimum, majuscule, minuscule, chiffre et symbole)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', SecurePassword::rule()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($auditor, $email, $name, $password, $role) {
            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role_id' => $role->id,
                'email_verified_at' => now(),
            ]);
            $user->forceFill(['active' => true])->save();

            $auditor->record(
                'user.bootstrap_super_admin',
                entity: $user,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'SUPER_ADMIN',
                    'active' => true,
                ],
                module: 'administration',
            );

            $auditor->record(
                'user.role.assign',
                entity: $user,
                newValues: ['role' => 'SUPER_ADMIN'],
                module: 'administration',
            );

            if ($this->option('replace-demo-users')) {
                $this->deactivateDemoUsers($auditor, $user);
            }

            return $user;
        });

        $this->info("Super Administrateur {$user->email} créé pour ".config('rivo.site.name').'.');

        if (! $this->option('replace-demo-users')) {
            $this->warn('Les éventuels comptes de démonstration n’ont pas été désactivés. Relancez avec --replace-demo-users après vérification du nouveau compte.');
        }

        return self::SUCCESS;
    }

    private function deactivateDemoUsers(Auditor $auditor, User $newUser): void
    {
        $demoUsers = User::query()
            ->whereIn('email', self::DEMO_EMAILS)
            ->where('id', '!=', $newUser->id)
            ->where('active', true)
            ->lockForUpdate()
            ->get();

        foreach ($demoUsers as $demoUser) {
            $demoUser->forceFill([
                'active' => false,
                'deactivated_by' => $newUser->id,
                'deactivated_at' => now(),
                'deactivation_reason' => 'Remplacement sécurisé du compte de démonstration.',
                'remember_token' => null,
            ])->save();

            DB::table(config('session.table', 'sessions'))->where('user_id', $demoUser->id)->delete();

            $auditor->record(
                'user.deactivate_demo',
                entity: $demoUser,
                newValues: ['active' => false],
                oldValues: ['active' => true],
                reason: 'Remplacement sécurisé du compte de démonstration.',
                module: 'administration',
                actor: $newUser,
            );
        }

        $this->line($demoUsers->count().' compte(s) de démonstration désactivé(s).');
    }
}
