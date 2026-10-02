<?php

namespace App\Http\Controllers\Administration;

use App\Enums\HrReferenceType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Support\Hr\EmployeeUsage;
use App\Models\HrReferenceValue;
use App\Services\Administration\HrPresenter;
use App\Services\Administration\InternshipDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-194 — tous les stages de la clinique : les contrats dont le type est
 * marqué « contrat de stage », lus avec leur filière, leur école et leur
 * encadrant. La page ne crée rien : un stagiaire est un dossier Employé
 * (photo, présences, planning de garde), son stage est un contrat.
 */
class InternshipController extends Controller
{
    private const STATUSES = InternshipDirectory::STATUSES;

    public function __construct(
        private readonly HrPresenter $presenter,
        private readonly InternshipDirectory $internships,
    ) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', EmploymentContract::class);

        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'current';
        $fieldUuid = (string) $request->query('field', '');
        $field = $fieldUuid !== ''
            ? HrReferenceValue::withTrashed()->ofType(HrReferenceType::InternshipField)->where('uuid', $fieldUuid)->first()
            : null;

        $contracts = $this->filtered($status, $search)
            ->when($field, fn (Builder $query) => $query->where('internship_field_id', $field->getKey()))
            ->with([
                'employee' => fn ($query) => $query->withTrashed()->with(['department', 'jobTitle']),
                'contractType' => fn ($query) => $query->withTrashed(),
                'internshipField', 'internshipSupervisor',
            ])
            ->orderByRaw('ends_on is null')->orderBy('ends_on')->orderBy('starts_on')
            ->paginate(20)->withQueryString()
            ->through(fn (EmploymentContract $contract) => $this->presenter->contract($contract));

        // ADR-243 — ce que la liste permet sur le dossier du stagiaire, comme celle des employés
        // (ADR-236) : archivé ou non, et ce qui empêcherait sa suppression définitive.
        $archived = collect($contracts->items())->map(fn (array $row) => $row['employee']['uuid'] ?? null)->filter()->unique();
        $dossiers = Employee::withTrashed()->whereIn('uuid', $archived)->get(['id', 'uuid', 'user_id', 'deleted_at'])->keyBy('uuid');
        $blockers = EmployeeUsage::blockersFor($dossiers->filter->trashed()->mapWithKeys(fn (Employee $employee) => [$employee->getKey() => $employee->user_id])->all());
        $contracts->through(function (array $row) use ($dossiers, $blockers): array {
            $dossier = $dossiers->get($row['employee']['uuid'] ?? '');
            $row['employee']['archived'] = (bool) $dossier?->trashed();
            $row['employee']['deletion_blockers'] = $dossier?->trashed() ? ($blockers[$dossier->getKey()] ?? []) : null;

            return $row;
        });

        // Chaque compte est ce que donnerait un clic, les autres filtres gardés
        // (ADR-119) : un chiffre qui ouvre une liste vide serait pire que rien.
        $statusCounts = collect(self::STATUSES)->mapWithKeys(fn (string $key) => [
            $key => $this->filtered($key, $search)
                ->when($field, fn (Builder $query) => $query->where('internship_field_id', $field->getKey()))
                ->count(),
        ]);
        $fieldCounts = $this->filtered($status, $search)
            ->selectRaw('internship_field_id, count(*) as aggregate')
            ->groupBy('internship_field_id')
            ->pluck('aggregate', 'internship_field_id');

        return Inertia::render('Administration/Internships/Index', [
            'internships' => $contracts,
            'filters' => ['q' => $search, 'status' => $status, 'field' => $field?->uuid],
            'counts' => $statusCounts,
            'fields' => HrReferenceValue::withTrashed()->ofType(HrReferenceType::InternshipField)
                ->orderBy('position')->orderBy('label')->get()
                ->filter(fn (HrReferenceValue $value) => (! $value->trashed() && $value->active) || $fieldCounts->has($value->id))
                ->map(fn (HrReferenceValue $value) => [
                    'uuid' => $value->uuid,
                    'label' => $value->label,
                    'count' => (int) ($fieldCounts[$value->id] ?? 0),
                ])->values(),
            // Un stage importé par fichier arrive sans filière : il se signale.
            'withoutField' => (int) $fieldCounts->get('', 0),
            'hasInternshipType' => $this->internships->hasInternshipType(),
        ]);
    }

    /** @return Builder<EmploymentContract> */
    private function filtered(string $status, string $search): Builder
    {
        return $this->internships->listing($status, $search);
    }
}
