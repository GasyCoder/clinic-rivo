<?php

namespace App\Actions\StaffAccess;

use App\Actions\User\CreateUserAction;
use App\Enums\AccountKind;
use App\Models\Employee;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\StaffAccessHandover;
use App\Models\StaffAccessHandoverItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-197 / ADR-202 — site : l'accès d'un employé, créé par le Super Admin.
 *
 * Le compte RIVO est créé par le chemin habituel (CreateUserAction : droits,
 * rôle, profil, lien à la fiche, audit), **sans mot de passe** : l'employé le
 * choisit lui-même à sa première connexion, en tapant son adresse sur la page de
 * connexion. Rien n'est gardé pour la remise au RH, qui ne fait que dire à
 * l'employé que son compte existe et où se connecter.
 *
 * Une remise déjà envoyée ne reçoit plus d'accès : les suivants partent dans une
 * nouvelle remise.
 */
final class GrantStaffAccessAction
{
    public function __construct(
        private readonly CreateUserAction $createUser,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{handover_uuid: string, email: string, mailbox_address?: ?string, role_id: int, professional_profile_id?: ?int}  $data
     * @return array{user: User, handover: StaffAccessHandover, item: StaffAccessHandoverItem}
     */
    public function execute(Employee $employee, array $data, CatalogActor $actor): array
    {
        return DB::transaction(function () use ($employee, $data, $actor): array {
            $employee = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            self::assertEligible($employee);

            $handover = StaffAccessHandover::query()->where('uuid', $data['handover_uuid'])->lockForUpdate()->first();
            if ($handover !== null && $handover->sent_at !== null) {
                throw ValidationException::withMessages(['handover_uuid' => 'Cette remise est déjà partie au RH : les accès suivants vont dans une nouvelle remise.']);
            }

            $identity = self::identity($actor);
            $handover ??= StaffAccessHandover::query()->create([
                'uuid' => $data['handover_uuid'],
                'created_by_uuid' => $identity['uuid'],
                'created_by_name' => $identity['name'],
            ]);

            $name = trim(collect([$employee->first_name, $employee->last_name])->filter()->join(' '));
            $user = $this->createUser->execute([
                'name' => $name !== '' ? $name : (string) $employee->employee_number,
                'email' => mb_strtolower($data['email']),
                'activation_on_first_login' => true,
                'role_id' => $data['role_id'],
                'professional_profile_id' => $data['professional_profile_id'] ?? null,
                'account_kind' => AccountKind::Staff->value,
                'employee_uuid' => $employee->uuid,
            ], $actor);

            // L'employé avait été marqué « sans accès » : l'accès créé l'emporte.
            if ($employee->access_waived_at !== null) {
                $employee->forceFill(['access_waived_at' => null, 'access_waived_reason' => null, 'access_waived_by_name' => null])->save();
            }

            $employee->loadMissing('jobTitle:id,label');
            $item = StaffAccessHandoverItem::query()->create([
                'staff_access_handover_id' => $handover->getKey(),
                'employee_id' => $employee->getKey(),
                'user_id' => $user->getKey(),
                'employee_name' => $user->name,
                'employee_number' => $employee->employee_number,
                'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                'login_email' => $user->email,
                'mailbox_address' => $data['mailbox_address'] ?? null,
                'role_label' => (string) Role::query()->whereKey($data['role_id'])->value('name'),
                'profile_label' => filled($data['professional_profile_id'] ?? null)
                    ? ProfessionalProfile::query()->whereKey($data['professional_profile_id'])->value('name')
                    : null,
                // Sa boîte recevra le mot de passe qu'il choisira (ADR-202).
                'mailbox_shares_password' => filled($data['mailbox_address'] ?? null),
            ]);

            $this->auditor->record('staff_access.grant', entity: $user, newValues: [
                'employee' => $employee->uuid,
                'email' => $user->email,
                'mailbox' => $data['mailbox_address'] ?? null,
                'handover' => $handover->uuid,
                'first_login_until' => $user->activation_open_until?->toIso8601String(),
            ], module: 'administration', actor: $actor->user());

            return ['user' => $user, 'handover' => $handover, 'item' => $item];
        });
    }

    public static function assertEligible(Employee $employee): void
    {
        if ($employee->trashed() || ! $employee->active) {
            throw ValidationException::withMessages(['employee_uuid' => 'Cet employé n’est plus en poste : aucun accès ne se crée pour lui.']);
        }

        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['employee_uuid' => 'Cet employé a déjà un compte RIVO.']);
        }
    }

    /** @return array{uuid: ?string, name: ?string} */
    public static function identity(CatalogActor $actor): array
    {
        if ($actor->user() !== null) {
            return ['uuid' => $actor->user()->uuid, 'name' => $actor->user()->name];
        }

        $external = $actor->externalAttribution('acting');

        return ['uuid' => $external['external_acting_by_uuid'], 'name' => $external['external_acting_by_name']];
    }
}
