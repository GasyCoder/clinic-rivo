<?php

namespace App\Console\Commands;

use App\Actions\User\BootstrapSuperAdminAction;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProvisionSuperAdmin extends Command
{
    /** @var string */
    protected $signature = 'rivo:provision-super-admin
        {email : Adresse email professionnelle unique sur le portail central}
        {--name= : Nom complet du responsable}
        {--replace-demo-users : Désactiver les anciens comptes de démonstration après création}';

    /** @var string */
    protected $description = 'Créer de façon sécurisée un Super Administrateur du portail central';

    /** @var array<int, string> */
    private const DEMO_EMAILS = [
        'test@example.com',
        'demo.surgeon@rivo.mg',
        'demo.surgeon2@rivo.mg',
        'demo.nurse@rivo.mg',
        'demo.nurse2@rivo.mg',
    ];

    public function handle(Auditor $auditor, BootstrapSuperAdminAction $bootstrap): int
    {
        if (config('rivo.site.type') !== 'admin') {
            $this->error('Cette commande est réservée au déploiement central RIVO_SITE_TYPE=admin.');

            return self::FAILURE;
        }

        if (! config('rivo.site.code') || ! config('rivo.site.name')) {
            $this->error('RIVO_SITE_CODE et RIVO_SITE_NAME doivent identifier ce site avant le provisionnement.');

            return self::FAILURE;
        }

        if (! Role::query()->where('code', 'SUPER_ADMIN')->exists()) {
            $this->error('Le rôle SUPER_ADMIN est absent. Exécutez les seeders RBAC avant cette commande.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Nom complet'));
        $password = (string) $this->secret('Mot de passe (12 caractères minimum, majuscule, minuscule, chiffre et symbole)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        try {
            $user = DB::transaction(function () use ($bootstrap, $auditor, $name, $password, $confirmation) {
                $user = $bootstrap->execute($name, (string) $this->argument('email'), $password, $confirmation);

                if ($this->option('replace-demo-users')) {
                    $this->deactivateDemoUsers($auditor, $user);
                }

                return $user;
            });
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info("Super Administrateur {$user->email} créé pour le portail central ".config('rivo.site.name').'.');

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
