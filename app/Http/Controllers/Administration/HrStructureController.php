<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveHrReferenceValueAction;
use App\Actions\Administration\CreateHrReferenceValueAction;
use App\Actions\Administration\RestoreHrReferenceValueAction;
use App\Actions\Administration\UpdateHrReferenceValueAction;
use App\Enums\HrReferenceType;
use App\Enums\HrStructureKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveHrReferenceRequest;
use App\Http\Requests\Administration\HrStructureRequest;
use App\Models\HrReferenceValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-188 — les modules Départements et Fonctions de l'espace RH.
 *
 * Ils écrivent le même référentiel que les Paramètres RH (ADR-066) avec les
 * mêmes actions et les mêmes droits (`hr_settings.*`) : un module ne crée pas
 * une seconde règle, il donne sa propre page à deux listes que les dossiers
 * Employé, le planning et l'import utilisent chaque jour. Servi au site et au
 * portail par `routes/hr.php` (ADR-187).
 *
 * ADR-194 — une fonction y liste aussi les départements où elle existe, et un
 * département les fonctions qui lui sont reliées : le dossier employé ne propose
 * que les fonctions du département choisi.
 */
class HrStructureController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', HrReferenceValue::class);

        $kind = HrStructureKind::fromRoute($request->route());
        $isJobTitles = $kind === HrStructureKind::JobTitles;
        $relation = $isJobTitles ? 'jobTitleEmployees' : 'departmentEmployees';

        $items = HrReferenceValue::withTrashed()
            ->ofType($kind->referenceType())
            // ADR-194 — les liens fonction ↔ département, lus des deux côtés.
            ->with($isJobTitles
                ? ['departments' => fn ($query) => $query->withTrashed()->orderBy('label')]
                : ['departmentJobTitles' => fn ($query) => $query->orderBy('label')])
            // Un dossier archivé ne compte plus : il ne travaille plus ici.
            ->withCount([
                "{$relation} as employees_count",
                "{$relation} as active_employees_count" => fn ($query) => $query->where('active', true),
            ])
            ->orderBy('position')
            ->orderBy('label')
            ->get()
            ->map(fn (HrReferenceValue $reference) => [
                'uuid' => $reference->uuid,
                'code' => $reference->code,
                'label' => $reference->label,
                'active' => (bool) $reference->active,
                'position' => (int) $reference->position,
                'archived' => $reference->trashed(),
                'delete_reason' => $reference->delete_reason,
                'employees_count' => (int) $reference->employees_count,
                'active_employees_count' => (int) $reference->active_employees_count,
                'departments' => $isJobTitles
                    ? $reference->departments->map(fn (HrReferenceValue $department) => [
                        'uuid' => $department->uuid,
                        'label' => $department->label,
                        'archived' => $department->trashed(),
                    ])->values()
                    : null,
                'job_titles' => $isJobTitles ? null : $reference->departmentJobTitles->pluck('label')->values(),
            ]);

        return Inertia::render('Administration/HrStructure/Index', [
            'kind' => $kind->value,
            'items' => $items,
            // Les départements proposés à une fonction ; un département archivé
            // n'est plus proposé, mais une fonction qui le porte le garde.
            'departmentOptions' => $isJobTitles
                ? HrReferenceValue::withTrashed()->ofType(HrReferenceType::Department)
                    ->orderBy('position')->orderBy('label')->get()
                    ->map(fn (HrReferenceValue $department) => [
                        'uuid' => $department->uuid,
                        'label' => $department->label,
                        'archived' => $department->trashed(),
                    ])->values()
                : [],
            // Pour un département : les fonctions sans aucun département, proposées partout.
            'sharedJobTitles' => $isJobTitles ? [] : HrReferenceValue::query()->ofType(HrReferenceType::JobTitle)
                ->whereDoesntHave('departments', fn ($query) => $query->withTrashed())->orderBy('label')->pluck('label')->values(),
        ]);
    }

    public function store(HrStructureRequest $request, CreateHrReferenceValueAction $action): RedirectResponse
    {
        $kind = HrStructureKind::fromRoute($request->route());
        $reference = $action->execute([
            ...$request->validated(),
            'type' => $kind->referenceType()->value,
            'active' => $request->validated('active', true),
            'position' => $request->validated('position') ?? 0,
        ], $request->user());

        return back()->with('status', "{$kind->singular()} « {$reference->label} » ajouté".($kind === HrStructureKind::JobTitles ? 'e.' : '.'));
    }

    public function update(HrStructureRequest $request, HrReferenceValue $reference, UpdateHrReferenceValueAction $action): RedirectResponse
    {
        $kind = $this->ensureKind($request, $reference);
        $data = $request->validated();
        $data['position'] = $data['position'] ?? $reference->position;
        $action->execute($reference, $data, $request->user());

        return back()->with('status', "{$kind->singular()} « {$reference->label} » mis".($kind === HrStructureKind::JobTitles ? 'e' : '').' à jour.');
    }

    public function destroy(ArchiveHrReferenceRequest $request, HrReferenceValue $reference, ArchiveHrReferenceValueAction $action): RedirectResponse
    {
        $kind = $this->ensureKind($request, $reference);
        $action->execute($reference, $request->validated('reason'), $request->user());

        return back()->with('status', "{$kind->singular()} « {$reference->label} » archivé".($kind === HrStructureKind::JobTitles ? 'e.' : '.'));
    }

    public function restore(Request $request, HrReferenceValue $reference, RestoreHrReferenceValueAction $action): RedirectResponse
    {
        $kind = $this->ensureKind($request, $reference);
        Gate::forUser($request->user())->authorize('restore', $reference);
        $action->execute($reference, $request->user());

        return back()->with('status', "{$kind->singular()} « {$reference->label} » restauré".($kind === HrStructureKind::JobTitles ? 'e.' : '.'));
    }

    /** Une fonction ne se modifie pas par l'adresse des départements, ni l'inverse. */
    private function ensureKind(Request $request, HrReferenceValue $reference): HrStructureKind
    {
        $kind = HrStructureKind::fromRoute($request->route());
        abort_unless($reference->type === $kind->referenceType(), 404);

        return $kind;
    }
}
