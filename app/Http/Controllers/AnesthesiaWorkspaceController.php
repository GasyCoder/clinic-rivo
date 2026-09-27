<?php

namespace App\Http\Controllers;

use App\Enums\AnesthesiaCaseStage;
use App\Models\SurgicalRequest;
use App\Services\Care\CareRecordReadModel;
use App\Services\Surgery\SurgicalCaseWorkspace;
use App\Services\Surgery\SurgicalReadinessPresenter;
use App\Support\SurgeryReferenceData;
use App\Support\SurgicalStayContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnesthesiaWorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $stage = AnesthesiaCaseStage::fromQuery($request->query('stage'));

        $searchable = function (Builder $query) use ($search): void {
            if ($search === '') {
                return;
            }

            $query->where(function ($case) use ($search): void {
                $case->where('procedure_name', 'like', "%{$search}%")
                    ->orWhereHas('episode', function ($episode) use ($search): void {
                        $episode->where('episode_number', 'like', "%{$search}%")
                            ->orWhereHas('patient', fn ($patient) => $patient
                                ->where('patient_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%"));
                    });
            });
        };

        // Les comptes ne suivent pas la recherche : une carte qui change de
        // valeur à chaque frappe cesse de dire combien de dossiers existent.
        $counts = collect(AnesthesiaCaseStage::cases())
            ->mapWithKeys(fn (AnesthesiaCaseStage $case) => [$case->value => $case->constrain(SurgicalRequest::query())->count()])
            ->all();

        $surgicalRequests = $stage->constrain(SurgicalRequest::query())
            ->with(['episode.patient', 'surgeon', 'anesthesiaRecord.anesthetist'])
            ->tap($searchable)
            ->when($stage === AnesthesiaCaseStage::Done,
                fn (Builder $query) => $query->orderByDesc('completed_at')->orderByDesc('id'),
                fn (Builder $query) => $query->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->orderBy('id'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SurgicalRequest $request) => array_merge($request->toArray(), [
                'stage' => AnesthesiaCaseStage::of($request)->value,
                'stage_label' => AnesthesiaCaseStage::of($request)->rowLabel(),
                'surgery_status' => $request->status->value,
            ]));

        return Inertia::render('Anesthesia/Index', [
            'surgicalRequests' => $surgicalRequests,
            'search' => $search,
            'stage' => $stage->value,
            'stages' => collect(AnesthesiaCaseStage::cases())
                ->map(fn (AnesthesiaCaseStage $case) => ['value' => $case->value, 'label' => $case->label()])
                ->all(),
            'counts' => $counts,
        ]);
    }

    public function show(
        Request $request,
        SurgicalRequest $surgicalRequest,
        SurgicalCaseWorkspace $workspace,
        CareRecordReadModel $careRecordReadModel,
        SurgicalReadinessPresenter $readiness,
    ): Response {
        $careRecord = $surgicalRequest->episode()->with('careRecord')->first()?->careRecord;

        return Inertia::render('Surgery/Show', [
            'workspace' => 'anesthesia',
            'surgicalRequest' => $workspace->loadForAnesthesia($surgicalRequest),
            'careSummary' => $careRecordReadModel->present($careRecord, $request->user()),
            // ADR-170 — même lecture pour les deux espaces : l'anesthésiste
            // voit où en est le bloc, le chirurgien voit où en est l'anesthésie.
            'readiness' => $readiness->present($surgicalRequest, $request->user()),
            // ADR-160 — le même séjour que la Chirurgie : un seul dossier (ADR-048).
            'hospitalStay' => SurgicalStayContext::for($surgicalRequest, $request->user()),
            'users' => [],
            'teamFunctions' => [],
            'procedures' => [],
            'anesthesiaItems' => SurgeryReferenceData::anesthesiaItems(),
        ]);
    }
}
