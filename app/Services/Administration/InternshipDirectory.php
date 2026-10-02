<?php

namespace App\Services\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * ADR-194 — un stage est un contrat dont le type est marqué « contrat de
 * stage » dans Paramètres RH. La règle vit ici une seule fois : la liste des
 * stages, le repère « Stagiaire » de l'annuaire et le formulaire de contrat
 * la lisent tous les trois.
 *
 * Le marquage est lu sur le type, jamais sur son code ni son libellé : un
 * type renommé « Stage académique » reste un contrat de stage.
 */
class InternshipDirectory
{
    /** Les vues de la page « Stages ». */
    /** ADR-243 — « archived » : les stagiaires dont le dossier est archivé, et eux seuls. */
    public const STATUSES = ['current', 'future', 'ended', 'all', 'archived'];

    /** @var Collection<int, int>|null */
    private ?Collection $typeIds = null;

    /** @return Collection<int, int> */
    public function contractTypeIds(): Collection
    {
        return $this->typeIds ??= HrReferenceValue::withTrashed()
            ->ofType(HrReferenceType::ContractType)
            ->get()
            ->filter(fn (HrReferenceValue $type) => $type->isInternshipContractType())
            ->pluck('id')
            ->values();
    }

    public function hasInternshipType(): bool
    {
        return HrReferenceValue::query()->ofType(HrReferenceType::ContractType)
            ->where('active', true)->get()
            ->contains(fn (HrReferenceValue $type) => $type->isInternshipContractType());
    }

    /** Les contrats de stage. */
    public function internships(Builder|Relation $query): Builder|Relation
    {
        return $query->whereIn('contract_type_id', $this->contractTypeIds()->all());
    }

    /** Les contrats en cours aujourd'hui. */
    public function current(Builder|Relation $query): Builder|Relation
    {
        $today = now()->toDateString();

        return $query->whereDate('starts_on', '<=', $today)
            ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    /** Un stage en cours aujourd'hui. */
    public function currentInternships(Builder|Relation $query): Builder|Relation
    {
        return $this->current($this->internships($query));
    }

    /**
     * ADR-207 — un stagiaire n'est pas un employé. Est stagiaire le dossier dont
     * le contrat qui compte aujourd'hui est un stage :
     *
     *   - un contrat en cours ou à venir existe → stagiaire si ce sont tous des stages ;
     *   - aucun → stagiaire si le dernier contrat terminé est un stage (ancien stagiaire).
     *
     * Embauché ensuite (CDD, CDI…), il redevient un employé. Un dossier sans
     * contrat reste un employé : rien ne permet de dire le contraire. Les
     * contrats archivés ne comptent pas.
     */
    public function interns(Builder $employees): Builder
    {
        $ids = $this->contractTypeIds()->all();
        $today = now()->toDateString();
        $ongoing = fn ($contract) => $contract->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
        $notInternship = fn ($contract) => $contract->where(fn ($type) => $type->whereNull('contract_type_id')->orWhereNotIn('contract_type_id', $ids));

        return $employees->where(fn (Builder $query) => $query
            // Un stage en cours ou à venir, et aucun autre contrat en cours ou à venir.
            ->where(fn (Builder $current) => $current
                ->whereHas('contracts', fn ($contract) => $ongoing($contract->whereIn('contract_type_id', $ids)))
                ->whereDoesntHave('contracts', fn ($contract) => $ongoing($notInternship($contract))))
            // Plus aucun contrat en cours : le dernier terminé est un stage.
            ->orWhere(fn (Builder $past) => $past
                ->whereDoesntHave('contracts', fn ($contract) => $ongoing($contract))
                ->whereHas('contracts', fn ($contract) => $contract
                    ->whereIn('contract_type_id', $ids)
                    ->whereNotExists(fn ($later) => $later->selectRaw('1')
                        ->from('employment_contracts as later')
                        ->whereColumn('later.employee_id', 'employment_contracts.employee_id')
                        ->whereNull('later.deleted_at')
                        ->where(fn ($type) => $type->whereNull('later.contract_type_id')->orWhereNotIn('later.contract_type_id', $ids))
                        ->whereColumn('later.ends_on', '>=', 'employment_contracts.ends_on')))));
    }

    /**
     * ADR-211 — parmi ces dossiers employés, lesquels sont des stagiaires. Une
     * seule requête pour une page de recherche de l'accueil.
     *
     * @param  iterable<int, int>  $employeeIds
     * @return Collection<int, int>
     */
    public function internIdsAmong(iterable $employeeIds): Collection
    {
        $ids = collect($employeeIds)->filter()->values();

        if ($ids->isEmpty() || $this->contractTypeIds()->isEmpty()) {
            return collect();
        }

        return $this->interns(Employee::query()->withTrashed()->whereKey($ids->all()))->pluck('id');
    }

    /** ADR-211 — un stagiaire n'a pas la prise en charge Personnel (ADR-194). */
    public function isIntern(Employee $employee): bool
    {
        return $this->internIdsAmong([$employee->getKey()])->isNotEmpty();
    }

    /** ADR-207 — les employés, stagiaires exclus. */
    public function withoutInterns(Builder $employees): Builder
    {
        return $employees->whereNot(fn (Builder $query) => $this->interns($query));
    }

    /**
     * Les stages de la page « Stages » pour une vue, une recherche et une filière :
     * écrit une fois pour la liste et pour ce qui en part (ADR-209 : les badges
     * des stagiaires affichés).
     *
     * @return Builder<EmploymentContract>
     */
    public function listing(string $status, string $search, ?HrReferenceValue $field = null): Builder
    {
        $today = now()->toDateString();

        return $this->internships(EmploymentContract::query())
            // ADR-243 — un dossier archivé ne se lit que dans « Archivés » ; ailleurs, jamais.
            ->when($status === 'archived',
                fn (Builder $query) => $query->whereHas('employee', fn ($employee) => $employee->onlyTrashed()),
                fn (Builder $query) => $query->whereHas('employee'))
            ->when($status === 'current', fn (Builder $query) => $this->current($query))
            ->when($status === 'future', fn (Builder $query) => $query->whereDate('starts_on', '>', $today))
            ->when($status === 'ended', fn (Builder $query) => $query->whereDate('ends_on', '<', $today))
            ->when($field, fn (Builder $query) => $query->where('internship_field_id', $field->getKey()))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('internship_school', 'like', "%{$search}%")
                    ->orWhere('internship_level', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('internshipField', fn ($field) => $field->where('label', 'like', "%{$search}%"));
            }));
    }
}
