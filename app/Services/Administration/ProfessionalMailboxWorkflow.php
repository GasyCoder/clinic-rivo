<?php

namespace App\Services\Administration;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Services\Catalog\CatalogActor;
use App\Support\ProfessionalEmailAddress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-190 — la vie d'une adresse email professionnelle, sur le site.
 *
 *   RH du site        demander, annuler sa demande
 *   Super Admin       créer (après la boîte chez l'hébergeur), refuser,
 *                     suspendre, réactiver
 *
 * Ce service ne parle jamais à l'hébergeur : seul le portail détient son
 * jeton. Il enregistre ce que le portail a fait, et refuse ce qui laisserait
 * l'état du site incohérent. Chaque transition est auditée (`Auditable`).
 */
final class ProfessionalMailboxWorkflow
{
    /** @param array{local_part: string, note?: ?string} $data */
    public function request(Employee $employee, array $data, CatalogActor $actor): ProfessionalMailbox
    {
        $this->authorize($actor, 'professional_emails.request');
        $this->ensureDomain();

        if (! $employee->active || $employee->trashed()) {
            throw ValidationException::withMessages(['local_part' => 'Cet employé n’est plus en poste : aucune adresse ne se demande pour lui.']);
        }

        $localPart = $this->localPart($data['local_part']);

        return $this->guardUnique(fn () => DB::transaction(function () use ($employee, $localPart, $data, $actor): ProfessionalMailbox {
            if (ProfessionalMailbox::query()->where('employee_id', $employee->getKey())->whereIn('status', ProfessionalMailboxStatus::openValues())->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['local_part' => 'Cet employé a déjà une adresse professionnelle, ou une demande en attente.']);
            }

            return ProfessionalMailbox::query()->create([
                'employee_id' => $employee->getKey(),
                'address' => ProfessionalEmailAddress::compose($localPart),
                'status' => ProfessionalMailboxStatus::Requested,
                'request_note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'requested_at' => now(),
                'requested_by' => $actor->localUserId(),
                ...$actor->externalAttribution('requested'),
            ]);
        }));
    }

    public function cancel(ProfessionalMailbox $mailbox, CatalogActor $actor): ProfessionalMailbox
    {
        $this->authorize($actor, 'professional_emails.request');

        return $this->transition($mailbox, [ProfessionalMailboxStatus::Requested], 'Seule une demande encore en attente s’annule.', fn (ProfessionalMailbox $locked) => [
            'status' => ProfessionalMailboxStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->localUserId(),
            ...$actor->externalAttribution('cancelled'),
        ]);
    }

    /**
     * La boîte existe désormais chez l'hébergeur (créée par le portail) :
     * l'adresse devient active et l'email de la fiche RH — donc celui du compte
     * RIVO quand il se crée depuis la fiche (ADR-188). Rejouée avec la même
     * adresse, elle ne fait rien de plus.
     */
    public function activate(ProfessionalMailbox $mailbox, string $address, CatalogActor $actor): ProfessionalMailbox
    {
        $this->authorize($actor, 'professional_emails.create');
        $address = mb_strtolower(trim($address));
        [$localPart, $domain] = array_pad(explode('@', $address, 2), 2, '');

        if ($domain !== ProfessionalEmailAddress::domain() || ! ProfessionalEmailAddress::isValidLocalPart($localPart)) {
            throw ValidationException::withMessages(['address' => 'L’adresse doit appartenir au domaine '.ProfessionalEmailAddress::domain().'.']);
        }

        $fresh = ProfessionalMailbox::query()->whereKey($mailbox->getKey())->firstOrFail();
        if ($fresh->status === ProfessionalMailboxStatus::Active && $fresh->address === $address) {
            return $fresh;
        }

        return $this->guardUnique(fn () => $this->transition($mailbox, [ProfessionalMailboxStatus::Requested], 'Cette demande n’est plus en attente : elle a déjà été traitée ou annulée.', function (ProfessionalMailbox $locked) use ($address): array {
            $employee = Employee::withTrashed()->whereKey($locked->employee_id)->lockForUpdate()->firstOrFail();
            $employee->forceFill(['email' => $address])->save();

            return [
                'address' => $address,
                'status' => ProfessionalMailboxStatus::Active,
                'decided_at' => now(),
                'activated_at' => now(),
            ];
        }, $actor));
    }

    public function reject(ProfessionalMailbox $mailbox, string $reason, CatalogActor $actor): ProfessionalMailbox
    {
        $this->authorize($actor, 'professional_emails.reject');

        return $this->transition($mailbox, [ProfessionalMailboxStatus::Requested], 'Seule une demande encore en attente se refuse.', fn () => [
            'status' => ProfessionalMailboxStatus::Rejected,
            'decided_at' => now(),
            'rejection_reason' => trim($reason),
        ], $actor);
    }

    /** La connexion est bloquée chez l'hébergeur ; la boîte et ses messages restent. */
    public function suspend(ProfessionalMailbox $mailbox, string $reason, CatalogActor $actor): ProfessionalMailbox
    {
        $this->authorize($actor, 'professional_emails.deactivate');
        if ($mailbox->status === ProfessionalMailboxStatus::Suspended) {
            return $mailbox;
        }

        return $this->transition($mailbox, [ProfessionalMailboxStatus::Active], 'Seule une adresse active se suspend.', fn () => [
            'status' => ProfessionalMailboxStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => trim($reason),
            ...$actor->externalAttribution('suspended'),
        ]);
    }

    public function reactivate(ProfessionalMailbox $mailbox, CatalogActor $actor): ProfessionalMailbox
    {
        $this->authorize($actor, 'professional_emails.activate');
        if ($mailbox->status === ProfessionalMailboxStatus::Active) {
            return $mailbox;
        }

        return $this->transition($mailbox, [ProfessionalMailboxStatus::Suspended], 'Seule une adresse suspendue se réactive.', fn () => [
            'status' => ProfessionalMailboxStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
            'external_suspended_by_uuid' => null,
            'external_suspended_by_name' => null,
        ]);
    }

    /**
     * @param  array<int, ProfessionalMailboxStatus>  $from
     * @param  callable(ProfessionalMailbox): array<string, mixed>  $changes
     */
    private function transition(ProfessionalMailbox $mailbox, array $from, string $refusal, callable $changes, ?CatalogActor $decider = null): ProfessionalMailbox
    {
        return DB::transaction(function () use ($mailbox, $from, $refusal, $changes, $decider): ProfessionalMailbox {
            $locked = ProfessionalMailbox::query()->whereKey($mailbox->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['mailbox' => $refusal]);
            }

            $locked->forceFill([
                ...$changes($locked),
                ...($decider ? $decider->externalAttribution('decided') : []),
            ])->save();

            return $locked;
        });
    }

    /** Deux demandes simultanées pour la même adresse ou le même employé : la base tranche, le message le dit. */
    private function guardUnique(callable $write): ProfessionalMailbox
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['local_part' => 'Cette adresse est déjà prise, ou cet employé a déjà une adresse ouverte.']);
        }
    }

    private function localPart(string $value): string
    {
        $localPart = mb_strtolower(trim($value));

        if (! ProfessionalEmailAddress::isValidLocalPart($localPart)) {
            throw ValidationException::withMessages(['local_part' => ProfessionalEmailAddress::LOCAL_PART_MESSAGE]);
        }

        return $localPart;
    }

    private function ensureDomain(): void
    {
        if (! ProfessionalEmailAddress::configured()) {
            throw ValidationException::withMessages(['local_part' => 'Le domaine des adresses professionnelles n’est pas configuré (RIVO_PROFESSIONAL_EMAIL_DOMAIN).']);
        }
    }

    private function authorize(CatalogActor $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action n’est pas autorisée.');
        }
    }
}
