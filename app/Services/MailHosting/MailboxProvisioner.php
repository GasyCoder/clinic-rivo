<?php

namespace App\Services\MailHosting;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\ProfessionalMailboxProvision;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\MailboxPassword;
use App\Support\ProfessionalEmailAddress;

/**
 * ADR-190 — ce qui se fait chez l'hébergeur, dans le bon ordre, où que ce soit.
 *
 * Le portail et le site (quand un RH a reçu le droit) suivent le même chemin :
 * l'hébergeur d'abord, l'enregistrement ensuite — un site ne dit jamais
 * « active » une boîte qui n'existe pas. Ce que l'hébergeur a déjà fait est
 * retenu (ProfessionalMailboxProvision) : une confirmation perdue ne fait
 * jamais recréer une boîte ni redemander une suspension.
 *
 * Chaque méthode répond un tableau : `ok`, `status` (HTTP), `message`, et selon
 * le cas `errors`, `address`, `password`, `confirmed`, `mailbox_uuid`. Un mot de
 * passe n'apparaît que dans la réponse, jamais en base ni dans l'audit.
 */
final class MailboxProvisioner
{
    public function __construct(
        private readonly CpanelMailboxClient $hosting,
        private readonly Auditor $auditor,
    ) {}

    public function hostingConfigured(): bool
    {
        return ProfessionalEmailAddress::configured() && $this->hosting->configured();
    }

    /** Pourquoi rien ne peut être fait chez l'hébergeur depuis ce serveur ; null si tout est prêt. */
    public function refusal(): ?string
    {
        if (! ProfessionalEmailAddress::configured()) {
            return 'Le domaine des adresses professionnelles n’est pas configuré (RIVO_PROFESSIONAL_EMAIL_DOMAIN).';
        }

        return $this->hosting->configured()
            ? null
            : 'L’accès à l’hébergeur n’est pas configuré sur ce serveur (RIVO_MAIL_HOSTING_URL, _USER, et _TOKEN ou _PASSWORD).';
    }

    /**
     * « Tester la connexion » : une lecture seule chez l'hébergeur, pour vérifier
     * les accès avant de créer quoi que ce soit.
     *
     * @return array<string, mixed>
     */
    public function checkConnection(): array
    {
        if ($refusal = $this->refusal()) {
            return $this->fail($refusal);
        }

        try {
            $count = $this->hosting->check();
        } catch (MailHostingException $exception) {
            return $this->fail($exception->getMessage());
        }

        return [
            'ok' => true,
            'status' => 200,
            'message' => "Connexion à l’hébergeur réussie : {$count} boîte".($count > 1 ? 's' : '').' sur le compte.',
        ];
    }

    /**
     * Prépare l'accès à l'hébergeur pendant que l'écran se remplit : rien n'est
     * créé ni enregistré, la connexion est seulement faite d'avance.
     *
     * @return array<string, mixed>
     */
    public function prepareConnection(): array
    {
        if ($refusal = $this->refusal()) {
            return $this->fail($refusal);
        }

        try {
            $this->hosting->prepare();
        } catch (MailHostingException $exception) {
            return $this->fail($exception->getMessage());
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Accès à l’hébergeur prêt.'];
    }

    /** @return array<string, mixed> */
    public function create(MailboxRegistry $registry, string $site, string $mailbox, string $localPart, User $actor): array
    {
        if ($refusal = $this->refusal()) {
            return $this->fail($refusal);
        }

        $found = $this->found($registry, $site, $mailbox, $actor);
        if (! $found['ok']) {
            return $found;
        }

        $provision = ProfessionalMailboxProvision::query()->where('site_code', $site)->where('mailbox_uuid', $mailbox)->first();

        if (! $provision && ($found['data']['status'] ?? null) !== ProfessionalMailboxStatus::Requested->value) {
            return $this->fail('Cette demande n’est plus en attente : elle a déjà été traitée ou annulée.');
        }

        $password = null;
        if (! $provision) {
            $address = mb_strtolower(trim($localPart)).'@'.ProfessionalEmailAddress::domain();
            $password = MailboxPassword::generate();

            try {
                $this->hosting->create($address, $password);
            } catch (MailHostingException $exception) {
                return $this->fail($exception->getMessage());
            }

            $provision = ProfessionalMailboxProvision::query()->create([
                'site_code' => $site,
                'mailbox_uuid' => $mailbox,
                'address' => $address,
                'created_by' => $actor->getKey(),
                'host_created_at' => now(),
            ]);
            $this->audit('professional_email.host_create', $provision, $site, $mailbox, $actor);
        }

        $confirmation = $registry->command($site, $mailbox, 'activate', ['address' => $provision->address], $actor);

        if ($confirmation['ok']) {
            $provision->forceFill(['site_confirmed_at' => now()])->save();
        }

        return [
            'ok' => true,
            'status' => 200,
            'mailbox_uuid' => $mailbox,
            'address' => $provision->address,
            // Seulement à la création réelle : un nouvel essai ne recrée rien, donc ne connaît aucun mot de passe.
            'password' => $password,
            'confirmed' => $confirmation['ok'],
            'message' => $confirmation['ok']
                ? "Boîte {$provision->address} créée et reliée à la fiche employé."
                : 'La boîte est créée chez l’hébergeur, mais l’activation n’a pas été enregistrée ('.($confirmation['message'] ?? 'site injoignable').'). Réessayez « Créer » : elle ne sera pas recréée.',
        ];
    }

    /**
     * « Nouvelle adresse » : la demande est d'abord enregistrée — la même que
     * celle d'un RH, donc la même trace —, puis la boîte est créée. Si
     * l'hébergeur refuse, la demande reste en attente et se reprend de là.
     *
     * @return array<string, mixed>
     */
    public function direct(MailboxRegistry $registry, string $site, string $employeeUuid, string $localPart, User $actor): array
    {
        if ($refusal = $this->refusal()) {
            return $this->fail($refusal);
        }

        $requested = $registry->request($site, [
            'employee_uuid' => $employeeUuid,
            'local_part' => mb_strtolower(trim($localPart)),
            'note' => 'Créée directement, sans demande préalable.',
        ], $actor);

        if (! $requested['ok'] || ! is_string($requested['data']['uuid'] ?? null)) {
            return $this->fail($requested['message'] ?? 'La demande n’a pas été enregistrée.', $requested['http_status'] ?? 422, $requested['errors'] ?? []);
        }

        $result = $this->create($registry, $site, $requested['data']['uuid'], $localPart, $actor);

        return $result['ok'] ? $result : $this->fail(
            'La demande est enregistrée, mais la boîte n’a pas été créée : '.$result['message'].' Vous pourrez réessayer depuis « Demandes ».',
        );
    }

    /**
     * La connexion est bloquée chez l'hébergeur d'abord : l'état ne dit
     * « suspendue » qu'une boîte réellement suspendue.
     *
     * @return array<string, mixed>
     */
    public function suspend(MailboxRegistry $registry, string $site, string $mailbox, string $reason, User $actor): array
    {
        return $this->hostThenRegistry($registry, $site, $mailbox, ProfessionalMailboxStatus::Active, 'suspend', ['reason' => $reason],
            'professional_email.host_suspend', true, fn (string $address) => $this->hosting->suspend($address), $actor);
    }

    /** @return array<string, mixed> */
    public function reactivate(MailboxRegistry $registry, string $site, string $mailbox, User $actor): array
    {
        return $this->hostThenRegistry($registry, $site, $mailbox, ProfessionalMailboxStatus::Suspended, 'reactivate', [],
            'professional_email.host_unsuspend', false, fn (string $address) => $this->hosting->unsuspend($address), $actor);
    }

    /**
     * Un nouveau mot de passe, montré une seule fois. L'état de l'adresse ne
     * change pas : il n'y a rien à enregistrer.
     *
     * @return array<string, mixed>
     */
    public function resetPassword(MailboxRegistry $registry, string $site, string $mailbox, User $actor): array
    {
        if ($refusal = $this->refusal()) {
            return $this->fail($refusal);
        }

        $found = $this->found($registry, $site, $mailbox, $actor);
        if (! $found['ok']) {
            return $found;
        }

        if (($found['data']['status'] ?? null) !== ProfessionalMailboxStatus::Active->value) {
            return $this->fail('Seule une adresse active reçoit un nouveau mot de passe.');
        }

        $address = $found['data']['address'];
        $password = MailboxPassword::generate();
        try {
            $this->hosting->changePassword($address, $password);
        } catch (MailHostingException $exception) {
            return $this->fail($exception->getMessage());
        }

        $this->audit('professional_email.password_reset', null, $site, $mailbox, $actor, $address);

        return [
            'ok' => true,
            'status' => 200,
            'address' => $address,
            'password' => $password,
            'confirmed' => true,
            'message' => "Nouveau mot de passe défini pour {$address}.",
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(string): void  $hostAction
     * @return array<string, mixed>
     */
    private function hostThenRegistry(MailboxRegistry $registry, string $site, string $mailbox, ProfessionalMailboxStatus $expected, string $command, array $payload, string $auditAction, bool $suspending, callable $hostAction, User $actor): array
    {
        if ($refusal = $this->refusal()) {
            return $this->fail($refusal);
        }

        $found = $this->found($registry, $site, $mailbox, $actor);
        if (! $found['ok']) {
            return $found;
        }

        $data = $found['data'];
        $provision = ProfessionalMailboxProvision::query()->where('site_code', $site)->where('mailbox_uuid', $mailbox)->first();
        // Déjà fait chez l'hébergeur lors d'un essai dont l'enregistrement n'a pas abouti.
        $alreadyAtHost = $provision && ($provision->host_suspended_at !== null) === $suspending;

        if (($data['status'] ?? null) === $expected->value && ! $alreadyAtHost) {
            try {
                $hostAction($data['address']);
            } catch (MailHostingException $exception) {
                return $this->fail($exception->getMessage());
            }
            $provision?->forceFill(['host_suspended_at' => $suspending ? now() : null])->save();
            $this->audit($auditAction, $provision, $site, $mailbox, $actor, $data['address']);
        }

        $result = $registry->command($site, $mailbox, $command, $payload, $actor);

        return $result['ok']
            ? ['ok' => true, 'status' => 200, 'message' => $result['message'] ?? 'Adresse mise à jour.']
            : $this->fail($result['message'] ?? 'L’adresse n’a pas été mise à jour.', $result['http_status'] ?? 422, $result['errors'] ?? []);
    }

    /** @return array<string, mixed> */
    private function found(MailboxRegistry $registry, string $site, string $mailbox, User $actor): array
    {
        $found = $registry->find($site, $mailbox, $actor);

        return $found['ok'] && is_array($found['data'] ?? null)
            ? $found
            : $this->fail($found['message'] ?? 'Le site ne répond pas.', ($found['http_status'] ?? 0) === 404 ? 404 : 422);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    private function fail(string $message, int $status = 422, array $errors = []): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'errors' => $errors];
    }

    /** Ce qui a été fait chez l'hébergeur, pour quel site — jamais un mot de passe. */
    private function audit(string $action, ?ProfessionalMailboxProvision $provision, string $site, string $mailbox, User $actor, ?string $address = null): void
    {
        $this->auditor->record($action, $provision, [
            'site_code' => $site,
            'mailbox_uuid' => $mailbox,
            'address' => $address ?? $provision?->address,
        ], module: 'professional_emails', actor: $actor);
    }
}
