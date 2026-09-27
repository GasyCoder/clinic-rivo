<?php

namespace App\Services\Administration;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'annuaire du personnel d'un site (ADR-066) : ce que la liste « Employés »
 * affiche pour un état et une recherche donnés.
 *
 * Écrit une fois, pour la liste et pour ce qui en part (ADR-209 : « les badges
 * de tous les employés affichés ») — deux copies du même filtre finiraient par
 * imprimer autre chose que ce que la liste montre.
 */
class EmployeeDirectory
{
    public const STATUSES = ['active', 'on_leave', 'inactive', 'archived', 'all'];

    public const DEFAULT_STATUS = 'active';

    public function __construct(
        private readonly InternshipDirectory $internships,
        private readonly LeaveToday $leaveToday,
    ) {}

    /** Un état inconnu retombe sur « Actifs » : un lien périmé ne montre jamais une liste vide par erreur. */
    public function status(mixed $value): string
    {
        return in_array($value, self::STATUSES, true) ? $value : self::DEFAULT_STATUS;
    }

    /** Les dossiers du personnel, stagiaires exclus (ADR-207). */
    public function staff(): Builder
    {
        return $this->internships->withoutInterns(Employee::query());
    }

    public function filtered(string $status, string $search): Builder
    {
        return $this->staff()
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'on_leave', fn ($query) => $this->leaveToday->employees($query->where('active', true)))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('profession', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('identity_document_number', 'like', "%{$search}%")
                        ->orWhereHas('department', fn ($reference) => $reference->where('label', 'like', "%{$search}%"))
                        ->orWhereHas('jobTitle', fn ($reference) => $reference->where('label', 'like', "%{$search}%"));
                });
            });
    }
}
