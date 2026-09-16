<?php

namespace App\Http\Controllers;

use App\Actions\Maternity\AcceptMaternityOrientationAction;
use App\Actions\Maternity\CompleteMaternityOrientationAction;
use App\Actions\Maternity\RecordMaternityProcedureAction;
use App\Actions\Maternity\RequestCesareanFromMaternityAction;
use App\Actions\Maternity\SaveMaternityRecordAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Http\Requests\UpdateMaternityRecordRequest;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Services\Care\CareRecordReadModel;
use App\Support\EpisodeQueuePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaternityController extends Controller
{
    public function index(Request $request, EpisodeQueuePresenter $presenter): Response
    {
        $filter = in_array($request->query('filter'), ['active', 'completed'], true) ? $request->query('filter') : 'active';
        $search = trim((string) $request->query('q', ''));
        $query = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Maternity->value)
            ->whereHas('episode', fn ($episode) => $episode->where('status', 'OPEN'))
            // A patient is never truly deletable (ADR-010) — under normal
            // operation this can never fail to match. It only guards against
            // data corruption bypassing Eloquent entirely (e.g. a raw
            // TRUNCATE on patients), so an orphaned row disappears from the
            // queue instead of fataling the whole page.
            ->whereHas('episode.patient');
        $counts = [
            'active' => (clone $query)->whereIn('status', ['PENDING', 'IN_PROGRESS'])->count(),
            'completed' => (clone $query)->where('status', 'COMPLETED')->count(),
        ];
        $orientations = $query
            ->with(['episode.patient', 'episode.billableItems', 'episode.serviceRequests', 'acceptedBy:id,name'])
            ->when($filter === 'completed', fn ($q) => $q->where('status', 'COMPLETED'), fn ($q) => $q->whereIn('status', ['PENDING', 'IN_PROGRESS']))
            ->when($search !== '', fn ($q) => $q->whereHas('episode', fn ($episode) => $episode
                ->where('episode_number', 'like', "%{$search}%")
                ->orWhereHas('patient', fn ($patient) => $patient
                    ->where('patient_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->orderBy($filter === 'completed' ? 'completed_at' : 'oriented_at', $filter === 'completed' ? 'desc' : 'asc')
            ->paginate(20)->withQueryString();
        $queueNumbers = $filter === 'completed' ? [] : $presenter->assignQueueNumbers($orientations->getCollection());
        $orientations->through(fn (EpisodeOrientation $orientation) => $presenter->present($orientation, $queueNumbers[$orientation->id] ?? null));

        return Inertia::render('Maternity/Index', compact('orientations', 'counts', 'filter', 'search'));
    }

    public function show(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        EpisodeQueuePresenter $presenter,
        CareRecordReadModel $careRecordReadModel,
    ): Response {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Maternity, 404);
        abort_unless($episodeOrientation->episode->patient, 404, 'Le dossier patient de ce passage est introuvable.');
        $episodeOrientation->load([
            'episode.patient', 'episode.patient.allergies', 'episode.billableItems', 'episode.serviceRequests',
            'episode.careRecord',
            'episode.maternityRecord.procedures.performer:id,name',
            'episode.maternityRecord.creator:id,name', 'episode.maternityRecord.updater:id,name',
            'acceptedBy:id,name',
        ]);
        $episode = $episodeOrientation->episode;
        $record = $episode->maternityRecord;
        $user = $request->user();

        // L'orientation Soins du même passage : c'est elle qui porte la
        // fiche, et son UUID est ce qui permet d'y renvoyer la sage-femme
        // plutôt que d'en recopier une seconde version ici (ADR-093).
        $careOrientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Care->value)
            ->latest('id')
            ->first();

        return Inertia::render('Maternity/Show', [
            // Les constantes du passage sont relevées une seule fois, par les
            // Soins, et lues partout ailleurs par cette même projection
            // (ADR-054) : Maternité était le seul module clinique à ne pas la
            // consommer, si bien qu'une sage-femme ne voyait ni la tension, ni
            // la température, ni les allergies déjà consignées. Elle reste en
            // lecture seule et filtrée côté serveur par `care.view` /
            // `vitals.view` / `patients.medical_history.view`.
            'careRecord' => $careRecordReadModel->present($episode->careRecord, $user),
            'careRecordUrl' => $careOrientation !== null && $user->can('care.update')
                ? "/care/orientations/{$careOrientation->uuid}"
                : null,
            'allergies' => $user->can('patients.medical_history.view')
                ? $episode->patient->allergies->map(fn ($allergy) => [
                    'uuid' => $allergy->uuid,
                    'substance' => $allergy->substance,
                    'reaction' => $allergy->reaction,
                    'severity' => $allergy->severity?->value,
                ])->values()
                : [],
            'orientation' => $presenter->present($episodeOrientation),
            'record' => $record,
            'procedureCatalog' => CatalogItem::query()
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Maternity->value)
                ->whereNotIn('code', ['MAT-CESAREAN-SIMPLE', 'MAT-CESAREAN-TWIN'])
                ->orderBy('name')->get(['uuid', 'code', 'name', 'unit']),
            'capabilities' => [
                'can_edit' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress
                    && $user->can($record ? 'maternity.update' : 'maternity.create'),
                'can_prenatal' => $user->can('maternity.prenatal.manage'),
                'can_labor' => $user->can('maternity.labor.manage'),
                'can_delivery' => $user->can('maternity.delivery.manage'),
                'can_newborn' => $user->can('maternity.newborn.manage'),
                'can_procedures' => $user->can('maternity.procedures.manage'),
                'can_complete' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress && $user->can('maternity.complete'),
            ],
        ]);
    }

    public function accept(Request $request, EpisodeOrientation $episodeOrientation, AcceptMaternityOrientationAction $action): RedirectResponse
    {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('maternity.orientations.show', $episodeOrientation)->with('status', 'Prise en charge Maternité commencée.');
    }

    public function save(UpdateMaternityRecordRequest $request, EpisodeOrientation $episodeOrientation, SaveMaternityRecordAction $action): RedirectResponse
    {
        $action->execute($episodeOrientation, $request->validated(), $request->user());

        return back()->with('status', 'Dossier Maternité enregistré.');
    }

    public function procedure(Request $request, EpisodeOrientation $episodeOrientation, RecordMaternityProcedureAction $action): RedirectResponse
    {
        $data = $request->validate([
            'catalog_item_uuid' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $action->execute($episodeOrientation, $data, $request->user());

        return back()->with('status', 'Acte Maternité enregistré.');
    }

    public function cesarean(Request $request, EpisodeOrientation $episodeOrientation, RequestCesareanFromMaternityAction $action): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['SIMPLE', 'TWIN'])], 'indication' => ['required', 'string', 'max:3000']]);
        $action->execute($episodeOrientation, $data['type'], $data['indication'], $request->user());

        return back()->with('status', 'Demande de césarienne transmise à Chirurgie sur le même passage.');
    }

    public function complete(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        CompleteMaternityOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('maternity.index')->with('status', 'Prise en charge Maternité terminée.');
    }
}
