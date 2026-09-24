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
use App\Enums\BillableItemStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Http\Requests\Care\CancelCareConsumableRequestRequest;
use App\Http\Requests\SaveMaternityRecordDraftRequest;
use App\Http\Requests\UpdateMaternityRecordRequest;
use App\Models\CareConsumableRequest;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecord;
use App\Models\MaternityRecordDraft;
use App\Models\PatientNewbornLink;
use App\Models\User;
use App\Services\Care\CareConsumableDirectory;
use App\Services\Care\CareRecordReadModel;
use App\Services\Episode\ActiveEpisodeBoard;
use App\Services\Maternity\MaternityQueue;
use App\Support\Documents\MaternitySheetSection;
use App\Support\EpisodeQueuePresenter;
use App\Support\MaternityActProfile;
use App\Support\MaternityReference;
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
    public function index(Request $request, ActiveEpisodeBoard $board, MaternityQueue $queue): Response
    {
        $view = $board->normalizeView($request->query('view'));
        $search = trim((string) $request->query('q', ''));
        $passages = $board->page(CatalogModule::Maternity, $view, $search, $request->user());
        // Une requête pour relier les UUID de la page à leurs identifiants
        // locaux, qui ne quittent jamais le serveur (ADR-005).
        $ids = Episode::query()
            ->whereIn('uuid', collect($passages->items())->pluck('uuid'))
            ->pluck('id', 'uuid');
        $followUps = $queue->followUps($ids->values());

        return Inertia::render('Maternity/Index', [
            'passages' => $passages,
            'counts' => $board->counts(CatalogModule::Maternity),
            'view' => $view,
            'search' => $search,
            'followUps' => $ids
                ->mapWithKeys(fn (int $id, string $uuid) => [$uuid => $followUps[$id] ?? ['medicine' => null, 'cesarean' => null]])
                ->all(),
        ]);
    }

    /**
     * ADR-177 — la vraie prise en charge à la Maternité : l'orientation qui
     * attendait est acceptée, sinon elle est créée à l'instant.
     */
    public function takeCharge(Request $request, Episode $episode, TakeChargeOfEpisodeAction $action): RedirectResponse
    {
        $orientation = $action->execute($episode, CatalogModule::Maternity, $request->user());

        return redirect()->route('maternity.orientations.show', $orientation)->with('status', $orientation->accepted_by === $request->user()->getKey()
            ? 'Prise en charge Maternité commencée.'
            : 'Cette patiente est déjà prise en charge à la Maternité par '.($orientation->acceptedBy?->name ?? 'une collègue').'.');
    }

    public function show(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        EpisodeQueuePresenter $presenter,
        CareRecordReadModel $careRecordReadModel,
        CareConsumableDirectory $consumables,
        MaternitySheetSection $maternitySheet,
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
            'episode.maternityRecord.creator:id,name', 'episode.maternityRecord.updater:id,name',
            'acceptedBy:id,name',
        ]);
        $episode = $episodeOrientation->episode;
        $record = $episode->maternityRecord;
        $user = $request->user();

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
                'can_edit' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress
                    && $user->can($record ? 'maternity.update' : 'maternity.create'),
                'can_prenatal' => $user->can('maternity.prenatal.manage'),
                'can_labor' => $user->can('maternity.labor.manage'),
                'can_delivery' => $user->can('maternity.delivery.manage'),
                'can_newborn' => $user->can('maternity.newborn.manage'),
                'can_procedures' => $user->can('maternity.procedures.manage'),
                'can_view_consumables' => $canViewConsumables,
                'can_request_consumables' => $canRequestConsumables,
                'can_cancel_consumables' => $canViewConsumables && $user->can('care_consumables.cancel'),
                'can_complete' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress && $user->can('maternity.complete'),
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
