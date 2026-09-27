<?php

namespace App\Http\Controllers\Administration;

use App\Enums\HrReferenceType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Services\Administration\EmployeeBadges;
use App\Services\Administration\EmployeeDirectory;
use App\Services\Administration\InternshipDirectory;
use App\Services\Settings\AppSettings;
use App\Support\Hr\BadgeDesign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-209 — les badges du personnel : celui d'une personne, ou une planche pour
 * tous les employés (ou stagiaires) affichés ou cochés.
 *
 * Imprimer un badge est le même geste qu'imprimer la fiche d'un employé : le
 * droit `employees.print`. Un dossier archivé n'a plus de badge : la personne
 * n'est plus à la clinique.
 */
class EmployeeBadgeController extends Controller
{
    public const SCOPES = ['employees', 'interns'];

    public function __construct(private readonly EmployeeBadges $badges) {}

    /** Le badge d'une personne. */
    public function show(Request $request, Employee $employee): Response
    {
        Gate::forUser($request->user())->authorize('view', $employee);

        return Inertia::render('Administration/Employees/Badges', [
            'badges' => [$this->badges->presentOne($employee)],
            'design' => $this->badges->design(),
            'source' => [
                'kind' => 'employee',
                'label' => $employee->last_name,
                'back_url' => route('administration.employees.show', $employee),
                'back_label' => 'Retour au dossier',
            ],
            // Le papier réglé pour le site (Paramètres › Badge du personnel) ; le RH le change à l'impression.
            'layout' => null,
            'refusal' => null,
            'limit' => EmployeeBadges::MAX_SHEET,
        ]);
    }

    /**
     * Une planche : les dossiers cochés, sinon tous ceux que la liste affiche
     * avec ses filtres — jamais seulement la page ouverte.
     */
    public function sheet(Request $request, EmployeeDirectory $directory, InternshipDirectory $internships): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Employee::class);

        $validated = $request->validate([
            'uuids' => ['nullable', 'array', 'max:'.EmployeeBadges::MAX_SHEET],
            'uuids.*' => ['uuid'],
            'status' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:120'],
            'field' => ['nullable', 'uuid'],
            'layout' => ['nullable', Rule::in(['sheet', 'card'])],
        ]);

        // La liste d'où part la planche : sa route le dit (/employees/badges, /internships/badges).
        $scope = in_array($request->route('scope'), self::SCOPES, true) ? $request->route('scope') : 'employees';
        $uuids = array_values(array_unique($validated['uuids'] ?? []));
        $search = trim((string) ($validated['q'] ?? ''));

        $query = match (true) {
            $uuids !== [] => Employee::query()->whereIn('uuid', $uuids),
            $scope === 'interns' => Employee::query()->whereIn('id', $this->internsListed($internships, $validated, $search)),
            default => $directory->filtered($directory->status($validated['status'] ?? null), $search),
        };

        // Un dossier archivé n'a plus de badge, quel que soit le filtre choisi.
        $query->withoutTrashed();

        $count = (clone $query)->count();
        $refusal = $count > EmployeeBadges::MAX_SHEET
            ? "{$count} badges demandés : au plus ".EmployeeBadges::MAX_SHEET.' à la fois. Filtrez la liste ou cochez les personnes.'
            : null;

        $employees = $refusal ? collect() : $query->orderBy('last_name')->orderBy('first_name')->get();

        return Inertia::render('Administration/Employees/Badges', [
            'badges' => $this->badges->present($employees),
            'design' => $this->badges->design(),
            'source' => [
                'kind' => $scope,
                'label' => $uuids !== [] ? 'selection' : 'filter',
                'back_url' => $scope === 'interns'
                    ? route('administration.internships.index', array_filter(['status' => $validated['status'] ?? null, 'q' => $search, 'field' => $validated['field'] ?? null]))
                    : route('administration.employees.index', array_filter(['status' => $validated['status'] ?? null, 'q' => $search])),
                'back_label' => $scope === 'interns' ? 'Retour aux stages' : 'Retour aux employés',
            ],
            // `card` : une carte par page d'emblée ; sinon, le papier réglé pour le site.
            'layout' => $validated['layout'] ?? null,
            'refusal' => $refusal,
            'limit' => EmployeeBadges::MAX_SHEET,
        ]);
    }

    /**
     * L'emblème (ou le logo) déposé pour le badge de ce site. Servi par une route
     * RH pour que le portail, qui relaie les écrans RH (ADR-187), montre celui du
     * site et non le sien. Rien de déposé : l'image de la clinique, dans l'application.
     */
    public function emblem(AppSettings $settings): StreamedResponse
    {
        $kind = (new BadgeDesign($settings))->emblemKind();
        abort_unless($kind !== null && $settings->assetExists($kind), 404);

        return Storage::disk(AppSettings::DISK)->response($settings->assetPath($kind), null, [
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Les stagiaires de la page « Stages » pour sa vue, sa recherche et sa filière.
     *
     * @param  array<string, mixed>  $validated
     */
    private function internsListed(InternshipDirectory $internships, array $validated, string $search): Builder
    {
        $status = in_array($validated['status'] ?? null, InternshipDirectory::STATUSES, true) ? $validated['status'] : 'current';
        $field = filled($validated['field'] ?? null)
            ? HrReferenceValue::withTrashed()->ofType(HrReferenceType::InternshipField)->where('uuid', $validated['field'])->first()
            : null;

        return $internships->listing($status, $search, $field)->select('employee_id');
    }
}
