<?php

namespace App\Services\Administration;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Services\Settings\AppSettings;
use App\Support\Hr\BadgeDesign;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * ADR-209 — le badge du personnel : ce qu'il montre de chaque personne, et
 * l'apparence réglée pour le site.
 *
 * Le même badge pour tout le personnel — médecin, infirmier, gardien, RH… — et
 * pour un stagiaire, qui porte en plus « Stagiaire », sa filière et la fin de
 * son stage. Tout est lu dans le dossier : rien ne se saisit sur le badge.
 */
class EmployeeBadges
{
    /** Au-delà, la page d'impression pèserait trop : on filtre ou on sélectionne. */
    public const MAX_SHEET = 300;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly InternshipDirectory $internships,
        private readonly HrPresenter $presenter,
    ) {}

    /** @return array<string, mixed> */
    public function design(): array
    {
        return (new BadgeDesign($this->settings))->toArray(
            route('administration.badges.emblem', ['v' => $this->settings->setting()?->updated_at?->timestamp ?? 0]),
        );
    }

    /** @return array<string, mixed> */
    public function presentOne(Employee $employee): array
    {
        return $this->present(new EloquentCollection([$employee]))[0];
    }

    /**
     * Les badges d'un ensemble de dossiers, en un nombre fixe de requêtes : qui est
     * stagiaire, et son stage.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $employees): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        // Les relations se chargent en une requête : il faut une collection Eloquent.
        $employees = $employees instanceof EloquentCollection ? $employees : new EloquentCollection($employees->all());

        $employees->loadMissing([
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
        ]);

        $ids = $employees->modelKeys();
        $interns = $this->internships->interns(Employee::query()->withTrashed()->whereKey($ids))->pluck('id')->flip();
        $stages = $this->internshipsOf($interns->keys()->all());

        return $employees->map(fn (Employee $employee) => $this->badge(
            $employee,
            $interns->has($employee->getKey()),
            $stages->get($employee->getKey()),
        ))->values()->all();
    }

    /** @return array<string, mixed> */
    private function badge(Employee $employee, bool $intern, ?EmploymentContract $stage): array
    {
        $option = $this->presenter->employeeOption($employee);

        return [
            'uuid' => $employee->uuid,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'name' => $option['name'],
            'photo_url' => $option['photo_url'],
            'employee_number' => $employee->employee_number,
            // La référence du badge saisie au dossier (« Badge ») ; à défaut, le matricule.
            'badge_number' => $this->filled($employee->badge),
            'department' => $employee->department?->label,
            'job_title' => $employee->jobTitle?->label ?? $this->filled($employee->profession),
            // Le code stable du référentiel choisit l'icône du badge, jamais le libellé (ADR-052).
            'department_code' => $employee->department?->code,
            'job_title_code' => $employee->jobTitle?->code,
            'is_intern' => $intern,
            'internship' => $intern ? [
                'field' => $stage?->internshipField?->label,
                'field_code' => $stage?->internshipField?->code,
                'ends_on' => $stage?->ends_on?->toDateString(),
            ] : null,
            'active' => (bool) $employee->active,
        ];
    }

    /**
     * Le stage qui compte pour chaque stagiaire : celui en cours, sinon celui à
     * venir, sinon le dernier terminé.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, EmploymentContract>
     */
    private function internshipsOf(array $employeeIds): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        $today = now()->toDateString();

        return $this->internships->internships(EmploymentContract::query())
            ->whereIn('employee_id', $employeeIds)
            ->with('internshipField')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $contracts) => $contracts->sortBy(fn (EmploymentContract $contract) => match (true) {
                // En cours d'abord, puis à venir (le plus proche), puis le dernier terminé.
                $contract->starts_on?->toDateString() <= $today && (! $contract->ends_on || $contract->ends_on->toDateString() >= $today) => '0',
                $contract->starts_on?->toDateString() > $today => '1'.$contract->starts_on->toDateString(),
                default => '2'.(99999999 - (int) $contract->ends_on?->format('Ymd')),
            })->first());
    }

    private function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
