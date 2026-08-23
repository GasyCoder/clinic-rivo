<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\CreateSurgicalRequestAction;
use App\Actions\Surgery\DischargeSurgicalRequestAction;
use App\Actions\Surgery\ScheduleSurgicalRequestAction;
use App\Actions\Surgery\UpdateSurgicalPreparationAction;
use App\Actions\Surgery\UpdateSurgicalRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\SurgicalTeamFunction;
use App\Http\Requests\DischargeSurgicalRequestRequest;
use App\Http\Requests\ScheduleSurgicalRequestRequest;
use App\Http\Requests\StoreSurgicalRequestRequest;
use App\Http\Requests\UpdateSurgicalPreparationRequest;
use App\Http\Requests\UpdateSurgicalRequestRequest;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Care\CareRecordReadModel;
use App\Services\Surgery\SurgicalCaseWorkspace;
use App\Support\SurgeryReferenceData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CDC GitHub §15/16 — Chirurgie's own case lifecycle (demande, programmation,
 * préparation, sortie). Sub-resources (préopératoire, équipe, intervention,
 * anesthésie, consommables, complications, soins, compte rendu) each have
 * their own thin controller — see routes/web.php.
 */
class SurgeryController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $surgicalRequests = SurgicalRequest::query()
            ->with(['episode.patient', 'surgeon'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('procedure_name', 'like', "%{$search}%")
                        ->orWhereHas('episode', function ($episodeQuery) use ($search) {
                            $episodeQuery->where('episode_number', 'like', "%{$search}%")
                                ->orWhereHas('patient', function ($patientQuery) use ($search) {
                                    $patientQuery->where('patient_number', 'like', "%{$search}%")
                                        ->orWhere('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Surgery/Index', [
            'surgicalRequests' => $surgicalRequests,
            'search' => $search,
        ]);
    }

    public function create(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $episodes = Episode::query()
            ->where('status', 'OPEN')
            ->with('patient')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('episode_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($patientQuery) use ($search) {
                            $patientQuery->where('patient_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();

        return Inertia::render('Surgery/Create', [
            'episodes' => $episodes,
            'search' => $search,
            'procedures' => $this->procedureOptions(),
        ]);
    }

    public function store(StoreSurgicalRequestRequest $request, CreateSurgicalRequestAction $action): RedirectResponse
    {
        $episode = Episode::query()->where('uuid', $request->validated('episode_uuid'))->firstOrFail();

        $catalogItem = $this->procedureFromUuid($request->validated('catalog_item_uuid'));
        $surgicalRequest = $action->execute($episode, [
            'catalog_item_id' => $catalogItem->id,
            'procedure_name' => $catalogItem->name,
            'procedure_details' => $request->validated('procedure_details'),
            'notes' => $request->validated('notes'),
        ]);

        return redirect()->route('surgery.show', $surgicalRequest)->with('status', 'Demande de chirurgie créée.');
    }

    public function show(
        SurgicalRequest $surgicalRequest,
        SurgicalCaseWorkspace $workspace,
        CareRecordReadModel $careRecordReadModel,
    ): Response {
        $viewer = request()->user();
        $canViewAnesthesia = $viewer->can('anesthesia.view');
        $careRecord = $surgicalRequest->episode()->with('careRecord')->first()?->careRecord;

        return Inertia::render('Surgery/Show', [
            'workspace' => 'surgery',
            'surgicalRequest' => $workspace->loadForSurgery($surgicalRequest, $canViewAnesthesia),
            'careSummary' => $careRecordReadModel->present($careRecord, $viewer),
            'users' => $workspace->activeUsers(),
            'teamFunctions' => array_map(
                fn ($case) => ['value' => $case->value],
                SurgicalTeamFunction::cases(),
            ),
            'procedures' => $this->procedureOptions(),
            'anesthesiaItems' => SurgeryReferenceData::anesthesiaItems(),
        ]);
    }

    public function update(UpdateSurgicalRequestRequest $request, SurgicalRequest $surgicalRequest, UpdateSurgicalRequestAction $action): RedirectResponse
    {
        $data = $request->safe()->except('catalog_item_uuid');

        if ($request->has('catalog_item_uuid')) {
            $catalogItem = $this->procedureFromUuid($request->validated('catalog_item_uuid'));
            $data['catalog_item_id'] = $catalogItem->id;
            $data['procedure_name'] = $catalogItem->name;
        }

        $action->execute($surgicalRequest, $data);

        return back()->with('status', 'Demande de chirurgie mise à jour.');
    }

    public function schedule(ScheduleSurgicalRequestRequest $request, SurgicalRequest $surgicalRequest, ScheduleSurgicalRequestAction $action): RedirectResponse
    {
        $surgeon = User::query()->findOrFail($request->validated('surgeon_id'));

        $action->execute($surgicalRequest, $surgeon, $request->validated('scheduled_at'));

        return back()->with('status', 'Intervention programmée.');
    }

    public function updatePreparation(UpdateSurgicalPreparationRequest $request, SurgicalRequest $surgicalRequest, UpdateSurgicalPreparationAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated('operating_room'), $request->validated('preparation_notes'));

        return back()->with('status', 'Préparation du bloc mise à jour.');
    }

    public function discharge(DischargeSurgicalRequestRequest $request, SurgicalRequest $surgicalRequest, DischargeSurgicalRequestAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->user(), $request->validated('notes'));

        return back()->with('status', 'Sortie de chirurgie enregistrée.');
    }

    /** @return Collection<int, CatalogItem> */
    private function procedureOptions()
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Surgery->value)
            ->where('code', 'like', 'SURG-%')
            ->orderByRaw("code = 'SURG-OTHER'")
            ->orderBy('name')
            ->get(['id', 'uuid', 'code', 'name']);
    }

    private function procedureFromUuid(string $uuid): CatalogItem
    {
        return CatalogItem::query()
            ->where('uuid', $uuid)
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Surgery->value)
            ->where('code', 'like', 'SURG-%')
            ->firstOrFail();
    }
}
