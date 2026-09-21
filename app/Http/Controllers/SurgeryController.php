<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\DischargeSurgicalRequestAction;
use App\Actions\Surgery\ScheduleSurgicalRequestAction;
use App\Actions\Surgery\UpdateSurgicalPreparationAction;
use App\Actions\Surgery\UpdateSurgicalRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\HospitalStayStatus;
use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTeamFunction;
use App\Http\Requests\DischargeSurgicalRequestRequest;
use App\Http\Requests\ScheduleSurgicalRequestRequest;
use App\Http\Requests\UpdateSurgicalPreparationRequest;
use App\Http\Requests\UpdateSurgicalRequestRequest;
use App\Models\CatalogItem;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Care\CareRecordReadModel;
use App\Services\Surgery\SurgicalCaseWorkspace;
use App\Support\SurgeryReferenceData;
use App\Support\SurgicalStayContext;
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

        // ADR-099/135 — la file du bloc se lit comme les autres : des vues
        // exclusives dont la somme est le nombre de dossiers, comptées par le
        // serveur (jamais depuis la page affichée) et filtrées par la carte.
        $views = [
            'to_plan' => [SurgicalRequestStatus::Pending->value],
            'planned' => [
                SurgicalRequestStatus::Scheduled->value,
                SurgicalRequestStatus::PreoperativeValidated->value,
            ],
            'in_block' => [SurgicalRequestStatus::InProgress->value],
            'done' => [
                SurgicalRequestStatus::Completed->value,
                SurgicalRequestStatus::Discharged->value,
            ],
        ];

        $view = array_key_exists((string) $request->query('view'), $views)
            ? (string) $request->query('view')
            : 'to_plan';

        $counts = [];

        foreach ($views as $key => $statuses) {
            $counts[$key] = SurgicalRequest::query()
                ->whereIn('status', $statuses)
                ->when($search !== '', fn ($query) => $this->applySearch($query, $search))
                ->count();
        }

        $surgicalRequests = SurgicalRequest::query()
            ->with(['episode.patient', 'surgeon', 'episode.hospitalStays' => fn ($query) => $query
                ->where('status', HospitalStayStatus::Active->value)
                ->select(['id', 'uuid', 'episode_id', 'service', 'room_bed'])])
            // A request Médecine withdrew before the block took it up is kept
            // for the record but must not sit in the working queue (ADR-084).
            ->where('status', '!=', SurgicalRequestStatus::Cancelled->value)
            ->whereIn('status', $views[$view])
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

        // ADR-159 — d'où vient chaque demande. Le libellé est servi par le
        // serveur : l'écran ne recopie pas un vocabulaire d'enum. Une demande
        // antérieure à cette colonne n'en porte aucune et le dit.
        $surgicalRequests->getCollection()->transform(function (SurgicalRequest $request) {
            $request->setAttribute('origin_label', $request->origin?->label());
            $request->setAttribute('origin_description', $request->origin?->description());
            $stay = $request->episode?->hospitalStays->first();
            $request->setAttribute('hospital_stay', $stay ? [
                'service' => $stay->service,
                'room_bed' => $stay->room_bed,
            ] : null);
            $request->episode?->unsetRelation('hospitalStays');

            return $request;
        });

        return Inertia::render('Surgery/Index', [
            'surgicalRequests' => $surgicalRequests,
            'search' => $search,
            'view' => $view,
            'counts' => $counts,
        ]);
    }

    /** La même recherche pour la liste et pour les compteurs. */
    private function applySearch($query, string $search)
    {
        return $query->where(function ($inner) use ($search) {
            $inner->where('procedure_name', 'like', "%{$search}%")
                ->orWhereHas('episode', fn ($episode) => $episode
                    ->where('episode_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($patient) => $patient
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")));
        });
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
            // ADR-160 — un patient hospitalisé descend au bloc et remonte à son
            // lit : l'équipe doit le savoir. Le fait est servi à qui voit le
            // dossier ; le lien vers le séjour, seulement avec le droit de l'ouvrir.
            'hospitalStay' => SurgicalStayContext::for($surgicalRequest, $viewer),
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
