<?php

namespace App\Services\Administration;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Support\ProfessionalEmailAddress;

/**
 * ADR-190 — les adresses d'un site, telles que la page RH du site et le
 * portail (par l'API) les lisent : une seule liste, les mêmes compteurs.
 */
final class ProfessionalMailboxDirectory
{
    public function __construct(private readonly ProfessionalMailboxPresenter $presenter) {}

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function listing(bool $withCandidates): array
    {
        $mailboxes = ProfessionalMailbox::query()
            ->with(['employee' => fn ($query) => $query->withTrashed(), 'employee.jobTitle:id,label', 'employee.department:id,label', 'requester:id,name'])
            ->latest('requested_at')->latest('id')
            ->limit(500)
            ->get()
            ->map(fn (ProfessionalMailbox $mailbox) => $this->presenter->present($mailbox));

        return [
            'data' => $mailboxes->values()->all(),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'domain' => ProfessionalEmailAddress::domain(),
                // Pour « Nouvelle adresse » : les employés en poste qui n'en ont pas d'ouverte.
                'candidates' => $withCandidates ? $this->candidates() : [],
                'summary' => [
                    'requested' => $mailboxes->where('status', ProfessionalMailboxStatus::Requested->value)->count(),
                    'to_suspend' => $mailboxes->where('to_suspend', true)->count(),
                    'active' => $mailboxes->where('status', ProfessionalMailboxStatus::Active->value)->count(),
                    'suspended' => $mailboxes->where('status', ProfessionalMailboxStatus::Suspended->value)->count(),
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function candidates(): array
    {
        if (! ProfessionalEmailAddress::configured()) {
            return [];
        }

        return Employee::query()
            ->where('active', true)
            ->whereDoesntHave('professionalMailboxes', fn ($query) => $query->whereIn('status', ProfessionalMailboxStatus::openValues()))
            ->with(['jobTitle:id,label', 'department:id,label'])
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(1000)
            ->get()
            ->map(fn (Employee $employee) => [
                'uuid' => $employee->uuid,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'employee_number' => $employee->employee_number,
                'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                'department' => $employee->department?->label,
                'email' => $employee->email,
                'suggestion' => ProfessionalEmailAddress::suggest($employee),
            ])
            ->all();
    }
}
