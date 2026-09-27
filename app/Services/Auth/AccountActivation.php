<?php

namespace App\Services\Auth;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\ProfessionalMailbox;
use App\Models\User;
use App\Services\Authorization\DeploymentAccountPolicy;

/**
 * ADR-202 — la première connexion d'un employé.
 *
 * Le Super Admin crée l'accès sans mot de passe ; le RH dit à l'employé « votre
 * compte est créé, connectez-vous sur ce lien ». À la connexion, l'employé tape
 * son adresse : si son compte attend sa première connexion, RIVO le salue par son
 * nom et sa fonction et lui demande de choisir son mot de passe ; sinon, il
 * demande le mot de passe, comme d'habitude.
 *
 * Seul un compte qui attend réellement sa première connexion s'ouvre ainsi : actif,
 * jamais activé, dans le délai, avec un rôle, et fait pour ce déploiement. Une
 * adresse inconnue reçoit la même réponse qu'un compte ordinaire (« mot de passe ») :
 * l'écran ne dit pas qui n'existe pas.
 */
final class AccountActivation
{
    public function __construct(private readonly DeploymentAccountPolicy $deployment) {}

    public function opensFor(?User $user): bool
    {
        return $user !== null
            && $user->awaitsActivation()
            && $user->role_id !== null
            && $user->role()->exists()
            && $this->deployment->allows($user);
    }

    public function find(string $email): ?User
    {
        return User::query()->where('email', mb_strtolower(trim($email)))->first();
    }

    /**
     * Ce que l'écran dit à la personne : « Bonjour Vola RABE, vous êtes médecin ».
     * La fonction de sa fiche employé d'abord, sinon son profil métier, sinon son rôle.
     *
     * @return array{name: string, first_name: string, job: ?string, site: ?string, email: string, mailbox: ?string}
     */
    public function greeting(User $user): array
    {
        $user->loadMissing(['employee.jobTitle:id,label', 'professionalProfile:id,name', 'role:id,name']);
        $employee = $user->employee;
        $first = trim((string) ($employee?->first_name ?? ''));

        return [
            'name' => $user->name,
            'first_name' => $first !== '' ? $first : $user->name,
            'job' => self::lowerJob($employee?->jobTitle?->label ?? $employee?->profession ?? $user->professionalProfile?->name ?? $user->role?->name),
            'site' => config('rivo.site.name') ?: null,
            'email' => $user->email,
            'mailbox' => $this->mailbox($user)?->address,
        ];
    }

    /** L'adresse professionnelle active de la fiche reliée au compte, s'il en a une. */
    public function mailbox(User $user): ?ProfessionalMailbox
    {
        $employeeId = $user->employee?->getKey();

        return $employeeId === null ? null : ProfessionalMailbox::query()
            ->where('employee_id', $employeeId)
            ->where('status', ProfessionalMailboxStatus::Active->value)
            ->latest('id')
            ->first();
    }

    public static function days(): int
    {
        return max(1, (int) config('rivo.account_activation.days', 14));
    }

    /** « Médecin » → « médecin » dans une phrase ; un sigle (« RH ») reste tel quel. */
    private static function lowerJob(?string $job): ?string
    {
        $job = trim((string) $job);

        if ($job === '') {
            return null;
        }

        $second = mb_substr($job, 1, 1);

        return $second !== '' && mb_strtoupper($second) === $second && mb_strtolower($second) !== $second
            ? $job
            : mb_strtolower(mb_substr($job, 0, 1)).mb_substr($job, 1);
    }
}
