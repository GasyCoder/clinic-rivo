<?php

namespace App\Http\Controllers;

use App\Actions\Care\CancelCareConsumableRequestAction;
use App\Actions\Episode\TakeChargeOfEpisodeAction;
use App\Actions\Maternity\AcceptMaternityOrientationAction;
use App\Actions\Maternity\CompleteMaternityOrientationAction;
use App\Actions\Maternity\ModifyMaternityProcedureAction;
use App\Actions\Maternity\RecordMaternityProcedureAction;
use App\Actions\Maternity\RecordMaternityProceduresAction;
use App\Actions\Maternity\RequestCesareanFromMaternityAction;
use App\Actions\Maternity\SaveMaternityRecordAction;
use App\Actions\Maternity\StartMaternityEncounterAction;
use App\Actions\Maternity\UpdatePregnancyDatingAction;
use App\Actions\Medicine\CancelParaclinicalRequestAction;
use App\Actions\Medicine\CreateImagingRequestAction;
use App\Actions\Medicine\CreateLabRequestAction;
use App\Enums\AdministrationRoute;
use App\Enums\AppointmentStatus;
use App\Enums\BillableItemStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MaternityEncounterType;
use App\Http\Requests\Care\CancelCareConsumableRequestRequest;
use App\Http\Requests\Maternity\StoreMaternityImagingRequestRequest;
use App\Http\Requests\Maternity\StoreMaternityLabRequestRequest;
use App\Http\Requests\SaveMaternityRecordDraftRequest;
use App\Http\Requests\StartMaternityEncounterRequest;
use App\Http\Requests\UpdateMaternityRecordRequest;
use App\Http\Requests\UpdatePregnancyDatingRequest;
use App\Models\CareConsumableRequest;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecord;
use App\Models\MaternityRecordDraft;
use App\Models\PatientNewbornLink;
use App\Models\Pregnancy;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Care\CareConsumableDirectory;
use App\Services\Care\CareRecordReadModel;
use App\Services\Episode\ActiveEpisodeBoard;
use App\Services\Maternity\MaternityEncounterDirectory;
use App\Services\Maternity\MaternityQueue;
use App\Services\Maternity\PregnancyParaclinicalHistory;
use App\Services\Maternity\PregnancyPresenter;
use App\Services\Maternity\PrenatalComparisonPresenter;
use App\Services\Maternity\PrenatalProtocolAdvisor;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use App\Services\Pharmacy\MedicineStockService;
use App\Support\Documents\MaternitySheetSection;
use App\Support\EpisodeQueuePresenter;
use App\Support\Maternity\MaternityEncounterFields;
use App\Support\Medicine\PrescriptionDocument;
use App\Support\MaternityActProfile;
use App\Support\MaternityReference;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaternityController extends Controller
{
    /**
     * ADR-177 — les passages ouverts, tels que la Maternité les voit.
     *
     * Un acte Maternité choisi à la Réception n'ouvre plus d'orientation qui
     * seule rendait la patiente visible : tout passage accueilli se voit ici,
     * suggéré « Maternité » ou non. La prise en charge reste un geste
     * (`takeCharge`) ; aucun dossier Maternité ne naît d'un regard.
     *
     * Ce qui suit la Maternité — le médecin, la césarienne — reste lu par
     * `MaternityQueue::followUps()`, deux requêtes pour toute la page.
     */
    public function index(
        Request $request,
        ActiveEpisodeBoard $board,
        MaternityQueue $queue,
        PregnancyPresenter $pregnancies,
        MaternityEncounterDirectory $encounters,
    ): Response {
        $view = $board->normalizeView($request->query('view'));
        $search = trim((string) $request->query('q', ''));
        // ADR-204 — consultations ou accouchements : un filtre posé après les
        // blocs, qui ne change ni la visibilité ni le n° de file d'un passage.
        $type = $encounters->normalize($request->query('type'));
        $refine = $encounters->refine($type);
        $passages = $board->page(CatalogModule::Maternity, $view, $search, $request->user(), refine: $refine);
        // Une requête pour relier les UUID de la page à leurs identifiants
        // locaux, qui ne quittent jamais le serveur (ADR-005).
        $ids = Episode::query()
            ->whereIn('uuid', collect($passages->items())->pluck('uuid'))
            ->pluck('id', 'uuid');
        $followUps = $queue->followUps($ids->values());
        $pregnancyContexts = $pregnancies->forEpisodes($ids->values());

        $encounterContexts = $encounters->forEpisodes($ids->values());

        return Inertia::render('Maternity/Index', [
            'passages' => $passages,
            'counts' => $board->counts(CatalogModule::Maternity, $refine),
            'view' => $view,
            'search' => $search,
            'type' => $type,
            // Chaque onglet compte ce que donnerait un clic, dans la vue ouverte.
            'typeCounts' => collect(MaternityEncounterDirectory::FILTERS)
                ->mapWithKeys(fn (string $filter): array => [$filter => $board->countIn(CatalogModule::Maternity, $view, $encounters->refine($filter))])
                ->all(),
            'encounterOptions' => MaternityEncounterType::options(),
            'encounters' => $ids
                ->mapWithKeys(fn (int $id, string $uuid) => [$uuid => $encounterContexts[$id] ?? null])
                ->all(),
            'followUps' => $ids
                ->mapWithKeys(fn (int $id, string $uuid) => [$uuid => $followUps[$id] ?? ['medicine' => null, 'cesarean' => null]])
                ->all(),
            'pregnancyContexts' => $ids
                ->mapWithKeys(fn (int $id, string $uuid) => [$uuid => $pregnancyContexts[$id] ?? null])
                ->all(),
        ]);
    }

    /**
     * ADR-177 — la vraie prise en charge à la Maternité : l'orientation qui
     * attendait est acceptée, sinon elle est créée à l'instant.
     */
    public function takeCharge(
        Request $request,
        Episode $episode,
        TakeChargeOfEpisodeAction $action,
        StartMaternityEncounterAction $start,
    ): RedirectResponse {
        $data = $request->validate(['encounter_type' => ['nullable', Rule::enum(MaternityEncounterType::class)]]);
        $orientation = $action->execute($episode, CatalogModule::Maternity, $request->user());
        $this->startChosenEncounter($orientation, MaternityEncounterType::tryFrom($data['encounter_type'] ?? ''), $request->user(), $start);

        return redirect()->route('maternity.orientations.show', $orientation)->with('status', $orientation->accepted_by === $request->user()->getKey()
            ? 'Prise en charge Maternité commencée.'
            : 'Cette patiente est déjà prise en charge à la Maternité par '.($orientation->acceptedBy?->name ?? 'une collègue').'.');
    }

    /**
     * ADR-204 — la prise en charge prise avec un parcours ouvre le dossier sur
     * ce parcours. Seulement pour qui vient de la prendre : une patiente déjà
     * suivie par une collègue n'est jamais réorientée d'ici.
     */
    private function startChosenEncounter(EpisodeOrientation $orientation, ?MaternityEncounterType $type, User $user, StartMaternityEncounterAction $start): void
    {
        if ($type === null || $orientation->accepted_by !== $user->getKey()) {
            return;
        }

        $record = $orientation->episode()->first()?->maternityRecord;

        if ($record === null ? $user->can('maternity.create') : ($record->encounter_type === null && ! $record->isFinalized() && $user->can('maternity.update'))) {
            $start->execute($orientation, $type, $user);
        }
    }

    /** ADR-204 — commencer (ou changer) le parcours : consultation prénatale ou accouchement. */
    public function startEncounter(
        StartMaternityEncounterRequest $request,
        EpisodeOrientation $episodeOrientation,
        StartMaternityEncounterAction $action,
    ): RedirectResponse {
        $record = $action->execute($episodeOrientation, $request->encounterType(), $request->user());

        return back()->with('status', $record->encounter_type === MaternityEncounterType::Delivery
            ? 'Parcours Accouchement ouvert.'
            : 'Consultation prénatale ouverte.');
    }

    /** ADR-204 — des analyses demandées depuis la Maternité, rattachées à ce dossier. */
    public function storeLabRequest(StoreMaternityLabRequestRequest $request, EpisodeOrientation $episodeOrientation, CreateLabRequestAction $action): RedirectResponse
    {
        $action->executeForMaternity($episodeOrientation, $request->validated('items'), $request->validated('notes'), $request->user());

        return back()->with('status', 'Analyses transmises au Laboratoire.');
    }

    /** ADR-204 — un examen d'imagerie demandé depuis la Maternité. */
    public function storeImagingRequest(StoreMaternityImagingRequestRequest $request, EpisodeOrientation $episodeOrientation, CreateImagingRequestAction $action): RedirectResponse
    {
        $action->executeForMaternity($episodeOrientation, $request->validated('items'), $request->validated('notes'), $request->user());

        return back()->with('status', 'Examen d’imagerie demandé.');
    }

    /** ADR-204 — retirer des analyses sans résultat, depuis la Maternité. */
    public function cancelLabRequest(Request $request, EpisodeOrientation $episodeOrientation, LabRequest $labRequest, CancelParaclinicalRequestAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->executeForMaternity($episodeOrientation, $labRequest, $validated['reason'] ?? null, $request->user());

        return back()->with('status', 'Analyses retirées. Ce qu’elles avaient porté au compte de la patiente est annulé.')->with('status_type', 'warning');
    }

    /** ADR-204 — retirer un examen d'imagerie sans compte rendu, depuis la Maternité. */
    public function cancelImagingRequest(Request $request, EpisodeOrientation $episodeOrientation, ImagingRequest $imagingRequest, CancelParaclinicalRequestAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->executeForMaternity($episodeOrientation, $imagingRequest, $validated['reason'] ?? null, $request->user());

        return back()->with('status', 'Examen d’imagerie retiré. Ce qu’il avait porté au compte de la patiente est annulé.')->with('status_type', 'warning');
    }

    public function show(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        EpisodeQueuePresenter $presenter,
        CareRecordReadModel $careRecordReadModel,
        CareConsumableDirectory $consumables,
        MaternitySheetSection $maternitySheet,
        PregnancyPresenter $pregnancies,
        PrenatalComparisonPresenter $comparison,
        PregnancyParaclinicalHistory $paraclinicalHistory,
        PrenatalProtocolAdvisor $advisor,
        ParaclinicalRequestPresenter $paraclinical,
        ImagingReportTemplateCatalog $templates,
        MedicineStockService $medicineStock,
    ): Response {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Maternity, 404);
        abort_unless($episodeOrientation->episode->patient, 404, 'Le dossier patient de ce passage est introuvable.');
        $episodeOrientation->load([
            'episode.patient', 'episode.patient.allergies', 'episode.billableItems', 'episode.serviceRequests',
            'episode.careRecord',
            'episode.maternityRecord.procedures.performer:id,name',
            'episode.maternityRecord.procedures.editor:id,name',
            'episode.maternityRecord.procedures.billableItem:id,status',
            'episode.maternityRecord.procedures.catalogItem:id,billable',
            'episode.maternityRecord.pregnancy',
            'episode.maternityRecord.creator:id,name', 'episode.maternityRecord.updater:id,name',
            'acceptedBy:id,name',
        ]);
        $episode = $episodeOrientation->episode;
        $record = $episode->maternityRecord;
        $user = $request->user();
        $activePregnancies = Pregnancy::query()
            ->where('patient_id', $episode->patient_id)
            ->ongoing()
            ->with(['maternityRecords.episode.careRecord', 'maternityRecords.orientation'])
            ->latest('started_at')->latest('id')->get();
        // Une seule candidate peut alimenter le contexte avant le choix. En
        // présence d'une incohérence historique (plusieurs actives), aucune
        // n'est choisie silencieusement.
        $contextPregnancy = $record?->pregnancy
            ?? ($activePregnancies->count() === 1 ? $activePregnancies->first() : null);

        // Ce que ce compte peut faire de chaque acte enregistré : l'écran ne montre
        // que les gestes permis, le serveur les revérifie (ADR-140).
        $canManage = $episodeOrientation->status === EpisodeOrientationStatus::InProgress
            && $user->can('maternity.procedures.manage');
        $record?->procedures->each(function (MaternityProcedure $procedure) use ($canManage, $user) {
            $locked = $procedure->isLockedFor($user);
            $procedure->setAttribute('locked_by_physician', $locked);
            $procedure->setAttribute('can_modify', $canManage && ! $locked);
            // Sans aucun montant : la sage-femme ne voit jamais un prix (ADR-036).
            $procedure->setAttribute('billing', $this->procedureBilling($procedure));
        });

        // ADR-142 — le matériel utilisé rejoint le circuit des consommables Soins :
        // demandé à la Pharmacie, encaissé par la Caisse. Une écriture sur le
        // passage, donc la même règle « prise en charge en cours » que les actes.
        $canViewConsumables = $user->can('care_consumables.view');
        $canRequestConsumables = $canManage && $user->can('care_consumables.request');

        $procedureCatalog = CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Maternity->value)
            ->whereNotIn('code', MaternityActProfile::CESAREAN_CODES)
            // Le matériel habituel de l'acte : une suggestion de saisie, jamais une règle.
            ->when($canRequestConsumables, fn ($query) => $query->with([
                'defaultConsumables.medicine' => fn ($medicine) => $medicine
                    ->where('active', true)
                    ->with('catalogItem:id,code,name,unit'),
            ]))
            ->orderBy('name')->get(['id', 'uuid', 'code', 'name', 'unit']);
        $recorded = $record?->procedures->pluck('catalog_item_uuid')->all() ?? [];
        $planned = $episode->serviceRequests
            ->filter(fn ($request) => $request->module === CatalogModule::Maternity
                && $procedureCatalog->contains('uuid', $request->catalog_item_uuid))
            ->map(fn ($request) => [
                'uuid' => $request->catalog_item_uuid,
                'code' => $request->catalog_code,
                'name' => $request->designation,
                'quantity' => (float) $request->quantity,
                'done' => in_array($request->catalog_item_uuid, $recorded, true),
            ])->values()->all();

        // L'orientation Soins du même passage : c'est elle qui porte la
        // fiche, et son UUID est ce qui permet d'y renvoyer la sage-femme
        // plutôt que d'en recopier une seconde version ici (ADR-093).
        $careOrientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Care->value)
            ->latest('id')
            ->first();

        // ADR-204 — le parcours, les examens et le rendez-vous de cette prise en charge.
        $finalized = (bool) $record?->isFinalized();
        $active = $episodeOrientation->status === EpisodeOrientationStatus::InProgress && ! $finalized;
        // ADR-205 — la sage-femme prescrit comme en consultation : mêmes droits,
        // et un dossier ouvert auquel l'ordonnance se rattache.
        $canPrescribe = $active && $record !== null
            && $user->can('prescriptions.create') && $user->can('medicines.view') && $user->can('stock.availability.view');
        $history = $contextPregnancy ? $paraclinicalHistory->forPregnancy($contextPregnancy, $user, $record) : null;
        $record?->loadMissing('appointment.creator:id,name');
        $upcoming = $contextPregnancy?->appointments()
            ->with('creator:id,name')
            ->where('status', AppointmentStatus::Scheduled->value)
            ->when($record, fn ($query) => $query->where(fn ($other) => $other
                ->whereNull('source_maternity_record_id')
                ->orWhere('source_maternity_record_id', '!=', $record->getKey())))
            ->orderBy('scheduled_at')
            ->get()
            ->map->present()
            ->values()
            ->all() ?? [];

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
            // ADR-204 — consultation prénatale ou accouchement : choisi, jamais
            // deviné. Un dossier d'avant ce choix se lit sur son contenu.
            'encounter' => [
                'type' => $record?->encounter_type?->value,
                'effective' => $record?->effectiveEncounterType()->value,
                'label' => $record?->effectiveEncounterType()->label(),
                'completion_label' => $record?->effectiveEncounterType()->completionLabel(),
                'suggested' => MaternityEncounterType::suggestedFor(collect($planned)->pluck('code'))?->value,
                'is_legacy' => $record !== null && $record->encounter_type === null,
                'finalized' => $finalized,
                'completed_at' => $record?->completed_at,
            ],
            'encounterOptions' => MaternityEncounterType::options(),
            'encounterFields' => MaternityEncounterFields::options(),
            // Les examens demandés depuis ce dossier : le Laboratoire et le
            // compte rendu d'imagerie restent la source de leurs résultats.
            'labRequests' => ! $user->can('laboratory_orders.view') ? null : ($record === null ? [] : LabRequest::query()
                ->where('maternity_record_id', $record->getKey())
                ->with(ParaclinicalRequestPresenter::LAB_RELATIONS)
                ->orderByDesc('requested_at')
                ->get()
                ->map(fn (LabRequest $lab) => $paraclinical->lab($lab, $active && $user->can('laboratory_orders.create')))
                ->all()),
            'imagingRequests' => ! $user->can('imaging_orders.view') ? null : ($record === null ? [] : ImagingRequest::query()
                ->where('maternity_record_id', $record->getKey())
                ->with(ParaclinicalRequestPresenter::IMAGING_RELATIONS)
                ->orderByDesc('requested_at')
                ->get()
                ->map(fn (ImagingRequest $imaging) => $paraclinical->imaging(
                    $imaging,
                    $user->can('imaging_results.create'),
                    $user->can('imaging_results.update'),
                    $active && $user->can('imaging_orders.create'),
                ))
                ->all()),
            // ADR-205 — les ordonnances écrites depuis ce dossier, et de quoi en écrire une.
            'prescriptions' => ! $user->can('prescriptions.view') ? null : ($record === null ? [] : Prescription::query()
                ->where('maternity_record_id', $record->getKey())
                ->with([...PrescriptionDocument::RELATIONS, 'lines'])
                ->orderByDesc('prescribed_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Prescription $prescription) => PrescriptionDocument::listItem(
                    $prescription,
                    $active && $user->can('prescriptions.cancel'),
                    "/maternity/orientations/{$episodeOrientation->uuid}/ordonnances/{$prescription->uuid}/impression",
                ))
                ->all()),
            'prescriptionOptions' => [
                'medicines' => $canPrescribe ? $medicineStock->availableCatalog() : [],
                'routes' => collect(AdministrationRoute::cases())->map(fn (AdministrationRoute $route) => [
                    'value' => $route->value, 'label' => $route->label(), 'short_label' => $route->shortLabel(),
                ])->values()->all(),
            ],
            'paraclinicalOptions' => [
                'lab_catalog' => $active && $record && $user->can('laboratory_orders.create') ? ParaclinicalRequestPresenter::catalog(CatalogModule::Laboratory) : [],
                'imaging_catalog' => $active && $record && $user->can('imaging_orders.create') ? ParaclinicalRequestPresenter::catalog(CatalogModule::Imaging) : [],
                'imaging_report_templates' => $user->can('imaging_results.create') ? $templates->all() : [],
                'imaging_report_template_rights' => $templates->rightsFor($user),
            ],
            'paraclinicalHistory' => $history,
            // Rappels indicatifs du suivi — jamais une demande, jamais un verrou.
            'prenatalAdvice' => $contextPregnancy
                ? $advisor->advise($contextPregnancy, $user, $episode->started_at ?? now(), $history)
                : null,
            'appointment' => $record?->appointment?->present(),
            'upcomingAppointments' => $upcoming,
            'pregnancySelectionRequired' => $record?->pregnancy_id === null,
            'activePregnancies' => $activePregnancies
                ->map(fn (Pregnancy $pregnancy) => $pregnancies->summary($pregnancy, $episode->started_at ?? now()))
                ->values(),
            // Le terme d'une consultation déjà enregistrée est son instantané :
            // une datation corrigée plus tard ne le réécrit pas (ADR-201).
            'pregnancy' => $contextPregnancy
                ? $pregnancies->summary($contextPregnancy, $episode->started_at ?? now(), $record)
                : null,
            'pregnancyHistory' => $contextPregnancy
                ? $pregnancies->history($contextPregnancy, $user, $record)
                : [],
            'previousPregnancies' => $pregnancies->previousForPatient(
                $episode->patient_id,
                $user,
                $contextPregnancy?->getKey(),
            ),
            'prenatalComparison' => $contextPregnancy
                ? $comparison->present($contextPregnancy, $episode, $record)
                : null,
            'procedureCatalog' => $procedureCatalog->map(fn (CatalogItem $item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                // « Autres » n'a de sens qu'avec sa précision (ADR-136).
                'requires_note' => $item->code === MaternityActProfile::OTHER_CODE,
                'default_consumables' => $canRequestConsumables
                    ? $item->defaultConsumables
                        ->filter(fn ($row) => $row->medicine && $row->medicine->catalogItem)
                        ->map(fn ($row) => [
                            'medicine_uuid' => $row->medicine->uuid,
                            'code' => $row->medicine->catalogItem->code,
                            'name' => $row->medicine->catalogItem->name,
                            'unit' => $row->medicine->catalogItem->unit,
                            'quantity' => $row->default_quantity,
                        ])->values()
                    : [],
            ])->values(),
            'consumableCatalog' => $canRequestConsumables
                ? $consumables->selectableConsumables(CatalogModule::Maternity)
                : [],
            // ADR-144 — les bébés de ce dossier qui ont déjà leur dossier patient, par identité de fiche.
            'newbornPatients' => $this->newbornPatients($record, $user),
            // ADR-145 — la même projection que le détail du passage : état, liens et création du dossier de chaque bébé.
            'babies' => $maternitySheet->forPassage($episode, $user, withCreation: true),
            'consumableRequests' => $canViewConsumables
                ? $consumables->forOrientation($episodeOrientation->getKey())
                : [],
            // Ce que la Réception a demandé à la Maternité, avec ce qui est
            // déjà enregistré : la sage-femme n'a pas à le retrouver dans une
            // liste (ADR-136).
            'plannedProcedures' => $planned,
            // Les repères rappelés pendant la saisie : une aide, jamais un verrou (ADR-137).
            'maternityReference' => MaternityReference::all(),
            // Les sections que ces actes mettent en avant — jamais un verrou.
            'actProfile' => MaternityActProfile::forCodes(collect($planned)->pluck('code')),
            // Une saisie n'a de sens que pendant la prise en charge.
            'recordDraft' => $episodeOrientation->status !== EpisodeOrientationStatus::InProgress
                ? null
                : MaternityRecordDraft::query()
                    ->where('episode_orientation_id', $episodeOrientation->getKey())
                    ->where('created_by', $user->getKey())
                    ->first(['payload', 'updated_at']),
            'capabilities' => [
                'can_edit' => $active && $user->can($record ? 'maternity.update' : 'maternity.create'),
                'can_prenatal' => $user->can('maternity.prenatal.manage'),
                'can_labor' => $user->can('maternity.labor.manage'),
                'can_delivery' => $user->can('maternity.delivery.manage'),
                'can_newborn' => $user->can('maternity.newborn.manage'),
                'can_procedures' => $user->can('maternity.procedures.manage'),
                'can_view_consumables' => $canViewConsumables,
                'can_request_consumables' => $canRequestConsumables,
                'can_cancel_consumables' => $canViewConsumables && $user->can('care_consumables.cancel'),
                'can_complete' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress && $user->can('maternity.complete'),
                'can_start_encounter' => $active && $user->can($record ? 'maternity.update' : 'maternity.create'),
                'can_request_lab' => $active && $record !== null && $user->can('laboratory_orders.create'),
                'can_request_imaging' => $active && $record !== null && $user->can('imaging_orders.create'),
                'can_prescribe' => $canPrescribe,
                'can_view_prescriptions' => $user->can('prescriptions.view'),
                'can_view_lab' => $user->can('laboratory_orders.view'),
                'can_view_imaging' => $user->can('imaging_orders.view'),
                'can_correct_dating' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress
                    && $record?->pregnancy_id !== null
                    && $user->can('maternity.update')
                    && $user->can('maternity.prenatal.manage'),
            ],
        ]);
    }

    /** La saisie en cours de la sage-femme, gardée pour qu'une actualisation ne la perde pas. */
    public function saveDraft(SaveMaternityRecordDraftRequest $request, EpisodeOrientation $episodeOrientation): JsonResponse
    {
        $draft = MaternityRecordDraft::query()->updateOrCreate(
            [
                'episode_orientation_id' => $episodeOrientation->getKey(),
                'created_by' => $request->user()->getKey(),
            ],
            ['payload' => $request->draftPayload()],
        );

        return response()->json(['saved_at' => $draft->updated_at->toIso8601String()]);
    }

    /** La sage-femme efface explicitement sa saisie. */
    public function discardDraft(Request $request, EpisodeOrientation $episodeOrientation): RedirectResponse
    {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Maternity, 404);

        MaternityRecordDraft::query()
            ->where('episode_orientation_id', $episodeOrientation->getKey())
            ->where('created_by', $request->user()->getKey())
            ->delete();

        return back()->with('status', 'Saisie en cours annulée.');
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

    public function updatePregnancyDating(
        UpdatePregnancyDatingRequest $request,
        EpisodeOrientation $episodeOrientation,
        UpdatePregnancyDatingAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->validated(), $request->user());

        return back()->with('status', 'Datation de la grossesse corrigée et auditée. Les snapshots des consultations précédentes restent inchangés.');
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

    /** Corriger la quantité ou la précision d'un acte enregistré (ADR-140). */
    public function updateProcedure(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        MaternityProcedure $procedure,
        ModifyMaternityProcedureAction $action,
    ): RedirectResponse {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $action->update($episodeOrientation, $procedure, $data, $request->user());

        return back()->with('status', 'Acte Maternité corrigé.');
    }

    /** Retirer un acte enregistré : il quitte la liste, l'audit garde la trace (ADR-140). */
    public function removeProcedure(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        MaternityProcedure $procedure,
        ModifyMaternityProcedureAction $action,
    ): RedirectResponse {
        $action->remove($episodeOrientation, $procedure, $request->user());

        return back()->with('status', 'Acte Maternité retiré.');
    }

    /** Un panier d'actes enregistré d'un seul geste : tout, ou rien (ADR-138). */
    public function procedures(Request $request, EpisodeOrientation $episodeOrientation, RecordMaternityProceduresAction $action): RedirectResponse
    {
        $data = $request->validate([
            // Le matériel peut partir seul (consommables du bébé) : au moins l'un des deux.
            'procedures' => ['required_without:consumables', 'array', 'max:30'],
            // Un acte n'entre qu'une fois dans le panier : la quantité porte les répétitions.
            'procedures.*.catalog_item_uuid' => ['required', 'uuid', 'distinct'],
            'procedures.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999'],
            'procedures.*.notes' => ['nullable', 'string', 'max:1000'],
            'consumables' => ['required_without:procedures', 'array', 'max:30'],
            'consumables.*.medicine_uuid' => ['required', 'uuid', 'distinct'],
            'consumables.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'consumable_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Déclarer du matériel a son propre droit : le champ n'est pas ignoré en silence.
        abort_if(! empty($data['consumables']) && ! $request->user()->can('care_consumables.request'), 403);

        $recorded = $action->execute(
            $episodeOrientation,
            $data['procedures'] ?? [],
            $request->user(),
            $data['consumables'] ?? [],
            $data['consumable_notes'] ?? null,
        );

        $parts = [];
        if (count($recorded) === 1) {
            $parts[] = 'Acte Maternité enregistré';
        } elseif (count($recorded) > 1) {
            $parts[] = count($recorded).' actes Maternité enregistrés';
        }
        if (! empty($data['consumables'])) {
            $parts[] = 'matériel transmis à la Pharmacie';
        }

        return back()->with('status', ucfirst(implode(' · ', $parts)).'.');
    }

    /**
     * @return array<string, array{uuid: string, patient_number: string, name: string, url: ?string}>
     */
    private function newbornPatients(?MaternityRecord $record, User $user): array
    {
        if ($record === null) {
            return [];
        }

        return PatientNewbornLink::query()
            ->where('maternity_record_id', $record->getKey())
            ->with('patient:id,uuid,patient_number,first_name,last_name')
            ->get()
            ->mapWithKeys(fn (PatientNewbornLink $link) => [$link->newborn_uuid => [
                'uuid' => $link->patient->uuid,
                'patient_number' => $link->patient->patient_number,
                'name' => trim("{$link->patient->last_name} {$link->patient->first_name}"),
                // Le lien n'est servi qu'à qui peut ouvrir un dossier patient.
                'url' => $user->can('patients.view') ? "/patients/{$link->patient->uuid}" : null,
            ]])
            ->all();
    }

    /** Annuler une demande de matériel tant que la Pharmacie n'a rien sorti du stock (ADR-072). */
    public function cancelConsumables(
        CancelCareConsumableRequestRequest $request,
        EpisodeOrientation $episodeOrientation,
        CareConsumableRequest $careConsumableRequest,
        CancelCareConsumableRequestAction $action,
    ): RedirectResponse {
        abort_unless(
            $episodeOrientation->destination_module === CatalogModule::Maternity
                && $careConsumableRequest->care_orientation_id === $episodeOrientation->getKey(),
            404,
        );

        $action->execute($careConsumableRequest, $request->validated('reason'), $request->user());

        return back()->with('status', 'Demande de matériel annulée. La Pharmacie ne la verra plus dans sa file.');
    }

    /**
     * Ce que la facturation d'un acte est devenue, sans montant (ADR-141, ADR-103).
     *
     * ```text
     * INVOICED      portée sur une facture — la Caisse encaisse
     * PENDING       chiffrée, en attente d'une facture
     * PLANNED       la Réception l'avait déjà facturée à l'arrivée
     * NOT_BILLABLE  le référentiel dit que cet acte n'est pas facturé
     * NOT_BILLED    personne n'a pu le chiffrer — à régulariser par la Réception
     * ```
     *
     * Un acte enregistré avant ce circuit n'a rien de facturé et ne le sera
     * pas rétroactivement : il est signalé, jamais inventé.
     *
     * @return array{state: string, label: string, needs_attention: bool}
     */
    private function procedureBilling(MaternityProcedure $procedure): array
    {
        $item = $procedure->billableItem;

        if (! $item) {
            $billable = (bool) $procedure->catalogItem?->billable;

            return [
                'state' => $billable ? 'NOT_BILLED' : 'NOT_BILLABLE',
                'label' => $billable ? 'Non facturé — à régulariser par la Réception' : 'Non facturable',
                'needs_attention' => $billable,
            ];
        }

        return match ($item->status) {
            BillableItemStatus::Invoiced => ['state' => 'INVOICED', 'label' => 'Sur facture', 'needs_attention' => false],
            BillableItemStatus::Cancelled => ['state' => 'CANCELLED', 'label' => 'Facturation annulée', 'needs_attention' => true],
            default => ['state' => $procedure->billing_origin === 'PLANNED' ? 'PLANNED' : 'PENDING',
                'label' => $procedure->billing_origin === 'PLANNED' ? 'Facturé à l’arrivée' : 'À la Caisse',
                'needs_attention' => false],
        };
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
        $data = $request->validate([
            'orient_to_medicine' => ['sometimes', 'boolean'],
            // Ce que la sage-femme veut dire au médecin ; jamais exigé (ADR-135).
            'medicine_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $toMedicine = (bool) ($data['orient_to_medicine'] ?? false);
        $action->execute($episodeOrientation, $request->user(), $toMedicine, $data['medicine_note'] ?? null);

        return redirect()->route('maternity.index', ['view' => 'completed'])
            ->with('status', $toMedicine
                ? 'Prise en charge Maternité terminée — la patiente est orientée vers Médecine.'
                : 'Prise en charge Maternité terminée.');
    }
}
