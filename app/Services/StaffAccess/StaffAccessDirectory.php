<?php

namespace App\Services\StaffAccess;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\StaffAccessHandover;
use App\Models\StaffAccessHandoverItem;
use App\Support\Hr\JobTitleAccountRole;
use App\Support\ProfessionalEmailAddress;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-197 — site : ce que le portail lit de l'accès du personnel.
 *
 *  - les employés en poste sans compte RIVO, qui attendent leur accès ;
 *  - ceux pour qui « aucun accès n'est nécessaire » (réversible) ;
 *  - les remises au RH récentes, et qui s'est déjà connecté (ADR-202) ;
 *  - les rôles et profils du site, pour choisir celui de chaque compte.
 *
 * Un employé archivé, inactif ou déjà relié à un compte n'attend rien.
 */
final class StaffAccessDirectory
{
    /** Ce qu'il faut du compte d'un employé pour dire où en est sa première connexion (ADR-202). */
    public const USER_COLUMNS = 'id,uuid,active,deactivated_at,activated_at,activation_open_until,last_login_at';

    /** Les remises montrées au portail : le mois écoulé suffit à les suivre. */
    private const HANDOVER_DAYS = 30;

    /** ADR-199 — le rôle que la fonction de chaque employé propose. */
    private ?JobTitleAccountRole $accountRoles = null;

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        return $this->employees()->whereNull('access_waived_at')->get()->map(fn (Employee $employee) => $this->employee($employee))->all();
    }

    /** @return list<array<string, mixed>> */
    public function waived(): array
    {
        return $this->employees()->whereNotNull('access_waived_at')->get()->map(fn (Employee $employee) => [
            ...$this->employee($employee),
            'waived_at' => $employee->access_waived_at?->toIso8601String(),
            'waived_reason' => $employee->access_waived_reason,
            'waived_by' => $employee->access_waived_by_name,
        ])->all();
    }

    /** L'état d'un employé, relu à l'instant par le portail avant de créer son accès. */
    public function state(Employee $employee): array
    {
        return [
            ...$this->employee($employee),
            'has_account' => $employee->user_id !== null,
            'active' => (bool) $employee->active && ! $employee->trashed(),
            'waived' => $employee->access_waived_at !== null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function handovers(): array
    {
        return StaffAccessHandover::query()
            ->with(['items.user:'.self::USER_COLUMNS])
            ->where('created_at', '>=', now()->subDays(self::HANDOVER_DAYS))
            ->latest()
            ->get()
            ->map(fn (StaffAccessHandover $handover) => $this->handover($handover))
            ->all();
    }

    /** @return array<string, mixed> Une remise, sans aucun mot de passe : ce que le portail et le RH lisent. */
    public function handover(StaffAccessHandover $handover): array
    {
        $handover->loadMissing(['items.user:'.self::USER_COLUMNS]);
        $status = $handover->status();
        $items = $handover->items->map(fn (StaffAccessHandoverItem $item) => $this->item($item))->all();
        $counts = collect($items)->countBy('state')->all();

        return [
            'uuid' => $handover->uuid,
            'status' => $status,
            'status_label' => StaffAccessHandover::statusLabel($status),
            'created_at' => $handover->created_at?->toIso8601String(),
            'created_by' => $handover->created_by_name,
            'sent_at' => $handover->sent_at?->toIso8601String(),
            'sent_by' => $handover->sent_by_name,
            'counts' => [
                'total' => count($items),
                'activated' => $counts[StaffAccessHandoverItem::STATE_ACTIVATED] ?? 0,
                'waiting' => $counts[StaffAccessHandoverItem::STATE_WAITING] ?? 0,
                'expired' => $counts[StaffAccessHandoverItem::STATE_EXPIRED] ?? 0,
            ],
            // Le délai le plus proche parmi ceux qui ne se sont pas encore connectés.
            'next_deadline' => collect($items)->where('state', StaffAccessHandoverItem::STATE_WAITING)->pluck('open_until')->filter()->sort()->first(),
            // Le jour où le dernier employé s'est connecté, quand tous l'ont fait.
            'completed_at' => $status === StaffAccessHandover::STATUS_COMPLETE
                ? collect($items)->pluck('activated_at')->filter()->sort()->last()
                : null,
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function item(StaffAccessHandoverItem $item): array
    {
        $state = $item->activationState();
        $user = $item->user;

        return [
            'uuid' => $item->uuid,
            'employee_name' => $item->employee_name,
            'employee_number' => $item->employee_number,
            'job_title' => $item->job_title,
            'login_email' => $item->login_email,
            'mailbox_address' => $item->mailbox_address,
            'role' => $item->role_label,
            'profile' => $item->profile_label,
            'state' => $state,
            'state_label' => StaffAccessHandoverItem::stateLabel($state),
            'open_until' => $state === StaffAccessHandoverItem::STATE_WAITING ? $user?->activation_open_until?->toIso8601String() : null,
            'activated_at' => ($user?->activated_at ?? $user?->last_login_at)?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> Les rôles qu'un compte peut recevoir, et leurs profils. */
    public function roles(): array
    {
        return Role::query()
            ->where('code', '!=', 'SUPER_ADMIN')
            ->with(['professionalProfiles' => fn ($query) => $query->active()->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'profiles' => $role->professionalProfiles->map(fn (ProfessionalProfile $profile) => [
                    'id' => $profile->id,
                    'code' => $profile->code,
                    'name' => $profile->name,
                ])->values()->all(),
            ])
            ->all();
    }

    /** @return Builder<Employee> */
    private function employees(): Builder
    {
        return Employee::query()
            ->where('active', true)
            ->whereNull('user_id')
            ->with(['jobTitle:id,type,label,metadata', 'department:id,label', 'professionalMailboxes' => fn ($query) => $query->latest('id')])
            ->orderByDesc('created_at')
            ->orderBy('last_name');
    }

    /** @return array<string, mixed> */
    private function employee(Employee $employee): array
    {
        $mailbox = $employee->professionalMailboxes
            ->first(fn (ProfessionalMailbox $mailbox) => in_array($mailbox->status->value, ProfessionalMailboxStatus::openValues(), true));

        return [
            'uuid' => $employee->uuid,
            'employee_number' => $employee->employee_number,
            'name' => trim(collect([$employee->first_name, $employee->last_name])->filter()->join(' ')),
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'job_title' => $employee->jobTitle?->label ?? $employee->profession,
            'department' => $employee->department?->label,
            'hire_date' => $employee->hire_date?->toDateString(),
            'created_at' => $employee->created_at?->toIso8601String(),
            'mailbox' => $mailbox ? [
                'uuid' => $mailbox->uuid,
                'status' => $mailbox->status->value,
                'address' => $mailbox->address,
            ] : null,
            'suggestion' => ProfessionalEmailAddress::configured() ? ProfessionalEmailAddress::suggest($employee) : null,
            // ADR-199 — prérempli dans la fenêtre de création, toujours modifiable.
            'proposed_access' => ($this->accountRoles ??= new JobTitleAccountRole)->forEmployee($employee),
        ];
    }
}
