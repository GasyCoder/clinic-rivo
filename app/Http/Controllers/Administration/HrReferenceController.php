<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveHrReferenceValueAction;
use App\Actions\Administration\CreateHrReferenceValueAction;
use App\Actions\Administration\RestoreHrReferenceValueAction;
use App\Actions\Administration\UpdateHrReferenceValueAction;
use App\Enums\HrReferenceType;
use App\Enums\LeaveDayCountMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveHrReferenceRequest;
use App\Http\Requests\Administration\HrReferenceDataRequest;
use App\Models\HrReferenceValue;
use App\Services\Administration\HrPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HrReferenceController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', HrReferenceValue::class);

        return Inertia::render('Administration/Settings/Index', [
            'references' => HrReferenceValue::withTrashed()->orderBy('type')->orderBy('position')->orderBy('label')
                ->get()->map(fn ($reference) => $this->presenter->reference($reference))->groupBy('type'),
            'types' => collect(HrReferenceType::cases())->map(fn ($type) => [
                'value' => $type->value, 'label' => $type->label(),
            ]),
            'leaveDayCountMethods' => collect(LeaveDayCountMethod::cases())->map(fn ($method) => [
                'value' => $method->value, 'label' => $method->label(),
            ]),
        ]);
    }

    public function store(HrReferenceDataRequest $request, CreateHrReferenceValueAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user());

        return back()->with('status', 'Valeur de paramétrage RH ajoutée.');
    }

    public function update(HrReferenceDataRequest $request, HrReferenceValue $reference, UpdateHrReferenceValueAction $action): RedirectResponse
    {
        if ($reference->type->value !== $request->validated('type') && $reference->isForceDeleteProtected()) {
            return back()->withErrors(['type' => 'Le type d’une valeur déjà utilisée ne peut pas être modifié.']);
        }

        $action->execute($reference, $request->validated(), $request->user());

        return back()->with('status', 'Paramètre RH mis à jour.');
    }

    public function destroy(ArchiveHrReferenceRequest $request, HrReferenceValue $reference, ArchiveHrReferenceValueAction $action): RedirectResponse
    {
        $action->execute($reference, $request->validated('reason'), $request->user());

        return back()->with('status', 'Valeur de paramétrage archivée.');
    }

    public function restore(Request $request, HrReferenceValue $reference, RestoreHrReferenceValueAction $action): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('restore', $reference);
        $action->execute($reference, $request->user());

        return back()->with('status', 'Valeur de paramétrage restaurée.');
    }
}
