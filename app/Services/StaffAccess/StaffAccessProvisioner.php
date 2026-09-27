<?php

namespace App\Services\StaffAccess;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\User;
use App\Services\MailHosting\MailboxProvisioner;
use App\Services\MailHosting\RemoteMailboxRegistry;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\ProfessionalEmailAddress;

/**
 * ADR-197 / ADR-202 — portail : l'accès d'un employé en un geste — l'adresse pro
 * chez l'hébergeur, puis le compte RIVO sur le site. **Sans mot de passe** :
 * l'employé choisit le sien à sa première connexion, et c'est le site qui le pose
 * aussi sur sa boîte.
 *
 * Dans cet ordre, et sans rien laisser à moitié :
 *
 *   1. l'état de l'employé est relu sur le site (pas de compte, en poste) ;
 *   2. le site vérifie le compte à venir (rôle, profil, adresse libre) avant
 *      qu'on touche à l'hébergeur ;
 *   3. la boîte est créée avec un mot de passe aléatoire que personne ne voit —
 *      une boîte déjà ouverte est reprise telle quelle ;
 *   4. le compte est créé sur le site, en attente de sa première connexion.
 *
 * Si l'étape 4 échoue, recommencer ne recrée pas la boîte.
 */
final class StaffAccessProvisioner
{
    public function __construct(
        private readonly PortalSiteApiClient $sites,
        private readonly MailboxProvisioner $mailboxes,
        private readonly RemoteMailboxRegistry $registry,
    ) {}

    /**
     * @param  array{handover_uuid: string, employee_uuid: string, local_part?: ?string, role_id: int, professional_profile_id?: ?int}  $data
     * @return array<string, mixed> `ok`, `status`, `message`, et en cas de succès `name`, `email`, `handover`
     */
    public function grant(string $site, array $data, User $actor): array
    {
        if ($refusal = $this->mailboxes->refusal()) {
            return $this->fail($refusal);
        }

        $state = $this->sites->staffAccessEmployee($site, $data['employee_uuid'], $actor);
        if (! $state['ok'] || ! is_array($state['data'] ?? null)) {
            return $this->fail($state['message'] ?? 'Le site n’a pas pu relire cet employé.', (int) ($state['http_status'] ?? 422), $state['errors'] ?? []);
        }

        $employee = $state['data'];
        if ($employee['has_account'] ?? false) {
            return $this->fail('Cet employé a déjà un compte RIVO.');
        }
        if (! ($employee['active'] ?? false)) {
            return $this->fail('Cet employé n’est plus en poste : aucun accès ne se crée pour lui.');
        }

        $mailbox = is_array($employee['mailbox'] ?? null) ? $employee['mailbox'] : null;
        $status = $mailbox['status'] ?? null;
        if ($status === ProfessionalMailboxStatus::Suspended->value) {
            return $this->fail('L’adresse '.$mailbox['address'].' est suspendue : réactivez-la dans « Emails professionnels », puis recommencez.');
        }

        $localPart = mb_strtolower(trim((string) ($data['local_part'] ?? '')));
        $address = $status === ProfessionalMailboxStatus::Active->value
            ? (string) $mailbox['address']
            : ($localPart !== '' ? ProfessionalEmailAddress::compose($localPart) : null);
        if ($address === null) {
            return $this->fail('Indiquez l’adresse professionnelle à créer.', 422, ['local_part' => ['Indiquez la partie de l’adresse avant « @ ».']]);
        }

        $account = [
            'handover_uuid' => $data['handover_uuid'],
            'employee_uuid' => $data['employee_uuid'],
            'email' => $address,
            'role_id' => $data['role_id'],
            'professional_profile_id' => $data['professional_profile_id'] ?? null,
        ];

        // 2. Rien ne touche l'hébergeur tant que le compte ne peut pas être créé.
        $check = $this->sites->grantStaffAccess($site, [...$account, 'dry_run' => true], $actor);
        if (! $check['ok']) {
            return $this->fail($check['message'] ?? 'Le site refuse ce compte.', (int) ($check['http_status'] ?? 422), $check['errors'] ?? []);
        }

        // 3. La boîte ; son mot de passe aléatoire n'est ni gardé ni montré.
        $box = $this->mailbox($site, $data['employee_uuid'], $mailbox, $localPart, $actor);
        if (! $box['ok']) {
            return $box;
        }

        // 4. Le compte, en attente de sa première connexion.
        $granted = $this->sites->grantStaffAccess($site, [
            ...$account,
            'email' => $box['address'],
            'mailbox_address' => $box['address'],
        ], $actor);

        if (! $granted['ok']) {
            return $this->fail(
                'L’adresse '.$box['address'].' est prête, mais le compte n’a pas été créé : '.($granted['message'] ?? 'le site a refusé').' Recommencez : l’adresse ne sera pas recréée.',
                (int) ($granted['http_status'] ?? 422),
                $granted['errors'] ?? [],
            );
        }

        return [
            'ok' => true,
            'status' => 201,
            'message' => $granted['message'] ?? 'Accès créé.',
            'name' => $granted['data']['user']['name'] ?? $employee['name'] ?? '',
            'email' => $box['address'],
            'handover' => $granted['data']['handover'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $mailbox
     * @return array<string, mixed> `ok`, `address`
     */
    private function mailbox(string $site, string $employeeUuid, ?array $mailbox, string $localPart, User $actor): array
    {
        $status = $mailbox['status'] ?? null;

        // Une adresse déjà ouverte est reprise : elle recevra le mot de passe que
        // l'employé choisira. Rien n'est changé chez l'hébergeur d'ici là.
        if ($status === ProfessionalMailboxStatus::Active->value) {
            return ['ok' => true, 'address' => (string) $mailbox['address']];
        }

        $created = $status === ProfessionalMailboxStatus::Requested->value
            ? $this->mailboxes->create($this->registry, $site, (string) $mailbox['uuid'], $localPart !== '' ? $localPart : explode('@', (string) $mailbox['address'])[0], $actor)
            : $this->mailboxes->direct($this->registry, $site, $employeeUuid, $localPart, $actor);

        if (! $created['ok']) {
            return $this->fail($created['message'] ?? 'La boîte n’a pas été créée.', (int) ($created['status'] ?? 422), $created['errors'] ?? []);
        }

        if (! ($created['confirmed'] ?? false)) {
            return $this->fail($created['message'] ?? 'Le site n’a pas enregistré la boîte : recommencez, elle ne sera pas recréée.');
        }

        // Le mot de passe aléatoire que l'hébergeur a reçu n'est pas rendu : l'employé
        // en choisira un autre à sa première connexion.
        return ['ok' => true, 'address' => (string) $created['address']];
    }

    /** @return array<string, mixed> */
    private function fail(string $message, int $status = 422, array $errors = []): array
    {
        return ['ok' => false, 'status' => $status >= 400 && $status < 600 ? $status : 422, 'message' => $message, 'errors' => $errors];
    }
}
