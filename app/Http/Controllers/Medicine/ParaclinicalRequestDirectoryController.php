<?php

namespace App\Http\Controllers\Medicine;

use App\Actions\Laboratory\ApproveLabResultsAction;
use App\Actions\Laboratory\ReceiveLabRequestAction;
use App\Actions\Medicine\ArchiveParaclinicalRequestAction;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\LabItemStatus;
use App\Http\Controllers\Controller;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\User;
use App\Services\Laboratory\LabPaymentClearance;
use App\Services\Laboratory\LabQueue;
use App\Services\Laboratory\LabResultAccess;
use App\Services\Laboratory\LabWorkbench;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use App\Support\ImagingReportDocument;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Toutes les demandes d'examens complémentaires, au même endroit.
 *
 * Elles n'étaient consultables que depuis la consultation qui les avait
 * créées : un médecin qui voulait savoir si l'échographie demandée ce matin
 * avait un résultat devait rouvrir le passage de tête.
 *
 * **Aucun statut n'est stocké.** `displayStatus()` le dérive de faits déjà
 * en base — `cancelled_at` sur la demande, `resulted_at` sur chaque ligne —
 * et les compteurs de cette page sont comptés sur ces mêmes faits, jamais
 * incrémentés côté navigateur.
 *
 * Amendement ADR-216 quater — un résultat d'analyse reçu attend la validation du
 * médecin (`approved_at`) : c'est un fait enregistré, pas une lecture supposée,
 * et il a sa vue « À valider ».
 */
class ParaclinicalRequestDirectoryController extends Controller
{
    /**
     * Trois vues, pas cinq statuts.
     *
     * L'écran listait un onglet par statut dérivé, si bien que le médecin
     * devait savoir lequel regarder pour trouver son travail. Les trois
     * groupes répondent à une question chacun :
     *
     *   to_validate  des résultats d'analyses reçus, à relire et valider
     *                (amendement ADR-216 quater) — ils passent avant tout le reste
     *   active    ce qui attend encore quelque chose
     *   recent    ce qui vient d'arriver et n'a peut-être pas été lu
     *   archived  ce qui est rangé — rendu il y a longtemps, ou retiré
     */
    private const FILTERS = ['to_validate', 'active', 'recent', 'archived'];

    /**
     * La frontière entre « récent » et « archivé ».
     *
     * Aucune règle du CDC ne la fixe : c'est un confort de lecture, jamais
     * une règle clinique. Rien n'est masqué pour autant — un résultat plus
     * ancien reste consultable dans « Archivées » et par la recherche.
     */
    private const RECENT_DAYS = 7;

    /**
     * Les familles d'examens, pour filtrer la liste (ADR-131).
     *
     * Celle d'un examen d'imagerie est réglée au catalogue (ADR-106), jamais
     * déduite d'un code : un examen non classé a sa propre famille plutôt que
     * d'être rangé au hasard dans l'une des deux autres.
     */
    private const TYPES = ['ECG', 'ULTRASOUND', 'LAB', 'UNCLASSIFIED'];

    public function index(Request $request): Response
    {
        $requestedFilter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : null;
        $search = trim((string) $request->query('q', ''));
        $type = in_array($request->query('type'), self::TYPES, true) ? (string) $request->query('type') : null;

        $canViewLab = $request->user()->can('laboratory_orders.view');
        $canViewImaging = $request->user()->can('imaging_orders.view');
        // Seule l'imagerie est saisissable ici : un résultat d'analyse
        // appartient au Laboratoire, qui possède `laboratory_results.create`
        // et son propre écran. Médecine n'y touche jamais.
        $canRecordImaging = $request->user()->can('imaging_results.create');
        $canCorrectImaging = $request->user()->can('imaging_results.update');
        // Ranger une demande lue : un drapeau daté, jamais une suppression.
        $canArchive = $request->user()->can('paraclinical_requests.archive');
        // Retirer, jamais supprimer (ADR-010) : la demande garde son
        // auteur, sa date et son numéro, et reste lisible au dossier.
        $canWithdraw = $request->user()->can('consultations.update');

        $rows = collect()
            ->concat($canViewLab ? $this->rows(LabRequest::query(), 'lab', 'Laboratoire', false, $canWithdraw, false, $canArchive, $request->user()) : [])
            ->concat($canViewImaging ? $this->rows(ImagingRequest::query(), 'imaging', 'Imagerie', $canRecordImaging, $canWithdraw, $canCorrectImaging, $canArchive, $request->user()) : []);

        $byType = fn (array $row): bool => $type === null || in_array($type, $row['families'], true);

        // Chaque compteur est ce que donnerait un clic : les vues comptent
        // sous la famille choisie, les familles sous la vue choisie.
        $counts = collect(self::FILTERS)
            ->mapWithKeys(fn (string $key) => [$key => $rows->filter($byType)->filter($this->group($key))->count()])
            ->all();

        // Par défaut, ce qui attend la validation du médecin quand il y en a ; sinon
        // « Active » : c'est le travail en cours, pas l'historique, qu'on vient chercher ici.
        $filter = $requestedFilter ?? ($counts['to_validate'] > 0 ? 'to_validate' : 'active');

        $inView = $rows->filter($this->group($filter));
        $typeCounts = ['ALL' => $inView->count()]
            + collect(self::TYPES)
                ->mapWithKeys(fn (string $key) => [$key => $inView->filter(fn (array $row) => in_array($key, $row['families'], true))->count()])
                ->all();

        $visible = $rows
            ->filter($byType)
            ->filter($this->group($filter))
            ->when($search !== '', fn (Collection $items) => $items->filter(
                fn (array $row) => str_contains(
                    mb_strtolower($row['patient']['name'].' '.$row['patient']['number'].' '.$row['episode_number'].' '.implode(' ', $row['exams'])),
                    mb_strtolower($search),
                ),
            ))
            // Le plus récent d'abord : une demande du jour intéresse plus
            // qu'une demande d'il y a trois semaines.
            ->sortByDesc('requested_at')
            ->values();

        return Inertia::render('Medicine/Requests', [
            'requests' => $visible,
            'counts' => $counts,
            'type_counts' => $typeCounts,
            'filters' => ['filter' => $filter, 'q' => $search, 'type' => $type],
            // `paraclinical_requests.view` ouvre l'écran ; ces deux-là
            // décident de ce qu'on y voit. Sans elles la liste est vide, et
            // un vide muet se lit « aucune demande » — l'écran doit dire que
            // c'est un droit qui manque, pas du travail terminé.
            'can' => ['lab' => $canViewLab, 'imaging' => $canViewImaging, 'approve' => $request->user()->can(ApproveLabResultsAction::PERMISSION)],
            // ADR-108 — les feuilles de la clinique, servies à l'écran de
            // saisie. Elles n'intéressent que qui peut écrire un compte
            // rendu : les envoyer à un compte qui ne fait que consulter
            // remplirait le payload d'un canevas qu'il ne verra jamais.
            'report_templates' => $canViewImaging ? app(ImagingReportTemplateCatalog::class)->all() : [],
            'report_template_rights' => app(ImagingReportTemplateCatalog::class)->rightsFor($request->user()),
        ]);
    }

    /** ADR-131 — ranger (ou ressortir) une demande d'examen rendue. */
    public function archive(Request $request, string $kind, string $uuid, ArchiveParaclinicalRequestAction $action, bool $archive = true): RedirectResponse
    {
        abort_unless(in_array($kind, ['lab', 'imaging'], true), 404);
        // Voir la famille est la condition pour la ranger : on n'agit pas sur
        // une liste qu'on n'a pas le droit de lire.
        abort_unless($request->user()->can($kind === 'lab' ? 'laboratory_orders.view' : 'imaging_orders.view'), 403);

        $action->execute($kind, $uuid, $archive, $request->user());

        return back()->with('status', $archive ? 'Demande archivée. Elle reste consultable dans « Archivées ».' : 'Demande sortie des archives.');
    }

    public function unarchive(Request $request, string $kind, string $uuid, ArchiveParaclinicalRequestAction $action): RedirectResponse
    {
        return $this->archive($request, $kind, $uuid, $action, false);
    }

    /**
     * À quel groupe appartient une ligne.
     *
     * Défini une seule fois : les compteurs et la liste doivent répondre la
     * même chose, sinon un onglet annonce un nombre puis n'affiche pas
     * autant de lignes.
     *
     * @return \Closure(array<string, mixed>): bool
     */
    private function group(string $filter): \Closure
    {
        $threshold = now()->subDays(self::RECENT_DAYS);

        $isRecent = fn (array $row): bool => $row['last_resulted_at'] !== null
            && Carbon::parse($row['last_resulted_at'])->greaterThanOrEqualTo($threshold);

        // Amendement ADR-216 quater — un résultat reçu et pas encore validé est du travail
        // pour le médecin : il passe avant les autres groupes, qui ne le comptent pas.
        $toValidate = fn (array $row): bool => $row['status'] !== 'CANCELLED' && ($row['approval']['awaiting'] ?? 0) > 0 && ! $row['sealed'];

        return match ($filter) {
            'to_validate' => $toValidate,
            'active' => fn (array $row): bool => ! $toValidate($row) && in_array($row['status'], ['REQUESTED', 'IN_PROGRESS'], true),
            // Une demande rangée à la main (ADR-131) n'est plus « récente »,
            // même rendue hier : c'est le médecin qui l'a décidé.
            'recent' => fn (array $row): bool => ! $toValidate($row) && $row['status'] === 'COMPLETED'
                && $row['archived_at'] === null
                && $isRecent($row),
            // Tout le reste : les demandes retirées, celles qu'on a rangées, et
            // les résultats rendus il y a plus longtemps. Rien ne disparaît,
            // tout se range.
            default => fn (array $row): bool => $row['status'] === 'CANCELLED'
                || (! $toValidate($row) && $row['status'] === 'COMPLETED' && ($row['archived_at'] !== null || ! $isRecent($row))),
        };
    }

    /**
     * @param  Builder<LabRequest|ImagingRequest>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function rows($query, string $kind, string $familyLabel, bool $canRecord = false, bool $canWithdraw = false, bool $canCorrect = false, bool $canArchive = false, ?User $viewer = null): Collection
    {
        $richText = app(ClinicalRichTextSanitizer::class);
        $catalog = app(ImagingReportTemplateCatalog::class);
        $labAccess = app(LabResultAccess::class);
        $isLab = $kind === 'lab';
        // ADR-216 — pour le prescripteur, une analyse n'est rendue qu'une fois
        // envoyée par le laboratoire ; un compte rendu d'imagerie, dès qu'il est écrit.
        $deliveredAt = fn ($item) => $isLab ? ($item->isDelivered() ? $item->resulted_at : null) : $item->resulted_at;
        $viewerId = $viewer?->getKey();
        $atBench = $isLab && (bool) $viewer?->can('laboratory_results.create');
        // Amendement ADR-216 du 2026-09-29 — un médecin à qui l'on accorde de modifier une
        // demande d'analyses l'ouvre au laboratoire, sans le geste de paillasse.
        $canManage = $isLab && ! $atBench && (bool) $viewer?->can('laboratory_orders.update') && (bool) $viewer?->can('laboratory_results.view');
        // ADR-219 — le geste de la file du laboratoire (Traiter, Continuer…) ; « Traiter »
        // prend la demande en charge, il demande ce droit-là.
        $canStart = $isLab && $viewer !== null && ReceiveLabRequestAction::canTakeUp($viewer);
        // ADR-219 — le règlement se lit comme dans la file du laboratoire (ADR-217) : pour
        // le laboratoire et qui voit la facturation, jamais un montant.
        $showPayment = $isLab && ($atBench || (bool) $viewer?->can('billing.view'));
        $clearance = app(LabPaymentClearance::class);
        $canApprove = $isLab && (bool) $viewer?->can(ApproveLabResultsAction::PERMISSION);

        return $query
            ->with([
                'items',
                'items.resultedBy:id,name',
                // Le document imprimable reprend la famille réglée au
                // catalogue et l'identité complète (ADR-108) : chargés ici une
                // fois, pas une requête par examen.
                'items.catalogItem:id,imaging_modality',
                'requestedBy:id,name',
                ...($isLab ? ['resultsRecipient:id,name', 'recipients:users.id,users.name', 'items.approvedBy:id,name'] : []),
                ...($showPayment ? LabPaymentClearance::RELATIONS : []),
                'consultation:id,episode_orientation_id,status,completed_at',
                'consultation.orientation:id,uuid,status',
                // ADR-162 — la demande faite depuis le séjour.
                'hospitalStay:id,uuid,status,episode_orientation_id',
                'hospitalStay.episodeOrientation:id,uuid,status',
                // ADR-204 — la demande faite depuis la Maternité.
                'maternityRecord:id,uuid,episode_orientation_id',
                'maternityRecord.orientation:id,uuid,status',
                'episode:id,uuid,episode_number,patient_id',
                'episode.patient:id,uuid,first_name,last_name,patient_number,sex,birth_date,birth_date_is_approximate,declared_age,address,address_entry_id',
                'episode.patient.addressEntry:id,label',
            ])
            // ADR-130 — qui a corrigé, et les versions remplacées : l'imagerie
            // seulement, les analyses n'ont ni l'un ni l'autre.
            ->when($kind === 'imaging', fn ($query) => $query->with([
                'items.correctedBy:id,name',
                'items.revisions.resultedBy:id,name',
                'items.revisions.supersededBy:id,name',
            ]))
            ->latest('requested_at')
            ->get()
            // Une demande orpheline de son passage ou de son patient ne
            // décrit plus rien d'exploitable : on l'écarte de l'écran
            // plutôt que d'y afficher des tirets.
            // ADR-216 — une analyse de l'accueil entre dans la liste du médecin
            // à qui le laboratoire l'a adressée, et du laboratoire lui-même.
            ->filter(fn ($request) => $request->episode?->patient !== null
                && ($request->consultation !== null || $request->hospitalStay !== null || $request->maternityRecord !== null
                    || ($isLab && ($atBench || ($viewer !== null && $request->isAddressedTo($viewer))))))
            ->map(fn ($request) => [
                'uuid' => $request->uuid,
                'kind' => $kind,
                'origin' => ParaclinicalRequestPresenter::origin($request),
                // ADR-216 — à qui le laboratoire a envoyé les résultats, et où les lire.
                'recipient' => $isLab ? $request->recipientNames() : null,
                'sealed' => $isLab && $labAccess->sealed($request, $viewer),
                'results_url' => $isLab && $request->items->contains(fn ($item) => $item->isDelivered())
                    ? "/resultats-analyses/{$request->uuid}"
                    : null,
                // Le compte du laboratoire ouvre la demande à la paillasse.
                'bench_url' => $atBench ? "/laboratory/requests/{$request->uuid}" : null,
                // ADR-219 — où en est le laboratoire (« À traiter »…) et le règlement, comme dans sa file.
                'lab_state' => $isLab && $request->cancelled_at === null ? LabQueue::stateOf($request) : null,
                'payment' => $showPayment && $request->cancelled_at === null
                    ? collect($clearance->for($request))->only(['cleared', 'exemption', 'exemption_label', 'due_count', 'unbilled_count'])->all()
                    : null,
                'bench_action' => $request->cancelled_at === null
                    ? ($atBench ? (($action = LabQueue::actionOf($request)) === 'start' && ! $canStart ? 'open' : $action) : ($canManage ? 'open' : null))
                    : null,
                'family_label' => $familyLabel,
                'status' => $request->displayStatus(),
                // Amendement ADR-216 quater — la validation du médecin, analyse par analyse.
                'approval' => $isLab ? [
                    'awaiting' => $request->items->filter(fn ($item) => $item->awaitsApproval())->count(),
                    'approved' => $request->items->filter(fn ($item) => $item->isApproved())->count(),
                    'total' => $request->items->count(),
                ] : null,
                'can_approve' => $isLab && $canApprove && $request->cancelled_at === null
                    && ! $labAccess->sealed($request, $viewer)
                    && $request->items->contains(fn ($item) => $item->awaitsApproval()),
                'requested_at' => $request->requested_at?->toIso8601String(),
                'requested_by' => $request->requestedBy?->name,
                'cancelled_at' => $request->cancelled_at?->toIso8601String(),
                'cancel_reason' => $request->cancel_reason,
                // ADR-131 — rangée à la main.
                'archived_at' => $request->archived_at?->toIso8601String(),
                'notes' => $request->notes,
                'episode_number' => $request->episode->episode_number,
                'patient' => [
                    'uuid' => $request->episode->patient->uuid,
                    'name' => trim($request->episode->patient->first_name.' '.$request->episode->patient->last_name),
                    'number' => $request->episode->patient->patient_number,
                ],
                'exams' => $request->items->pluck('catalog_item_name_snapshot')->all(),
                // Les familles présentes dans la demande, pour le filtre : une
                // demande peut réunir plusieurs familles d'imagerie.
                'families' => $kind === 'lab'
                    ? ['LAB']
                    : $request->items
                        ->map(fn ($item) => match ($item->catalogItem?->imaging_modality?->value) {
                            'CARDIOLOGY' => 'ECG',
                            'ULTRASOUND' => 'ULTRASOUND',
                            default => 'UNCLASSIFIED',
                        })
                        ->unique()->values()->all(),
                // Ligne par ligne : c'est l'examen qui porte son compte rendu,
                // pas la demande. Une demande de deux examens peut n'en avoir
                // qu'un de rendu.
                'items' => $request->items->map(fn ($item) => [
                    // UUID, jamais l'id SQL : c'est l'identifiant que la
                    // route lie et le seul exposé (ADR-005).
                    'uuid' => $item->uuid,
                    'exam' => $item->catalog_item_name_snapshot,
                    'resulted_at' => $deliveredAt($item)?->toIso8601String(),
                    'resulted_by' => $deliveredAt($item) ? $item->resultedBy?->name : null,
                    // Un résultat d'analyse se lit sur sa page (ADR-216), jamais ici :
                    // seul le compte rendu d'imagerie est servi dans la liste.
                    'report' => $isLab ? null : $richText->toSafeHtml($item->result_value),
                    // Le texte tel qu'il est stocké, pour rouvrir l'éditeur : déjà
                    // assaini à l'écriture, et distinct de `report`, qui est le
                    // rendu destiné à l'affichage.
                    'report_raw' => $isLab ? null : $item->result_value,
                    'notes_raw' => $isLab ? null : $item->result_notes,
                    'in_correction' => $isLab && $item->isDelivered() && $item->currentStatus() === LabItemStatus::ToRedo,
                    'approval' => $isLab ? app(LabWorkbench::class)->approval($item) : null,
                    // ADR-108 — la feuille à pré-appliquer à l'ouverture de la saisie.
                    'default_template_key' => $kind === 'imaging' ? $catalog->defaultKeyFor($item) : null,
                    // ADR-130 — une correction se lit : qui, quand.
                    'corrected_at' => $kind === 'imaging' ? $item->corrected_at?->toIso8601String() : null,
                    'corrected_by' => $kind === 'imaging' ? $item->correctedBy?->name : null,
                    'revisions' => $kind !== 'imaging' ? [] : $item->revisions->map(fn ($revision) => [
                        'uuid' => $revision->uuid,
                        'revision' => $revision->revision,
                        'resulted_at' => $revision->resulted_at?->toIso8601String(),
                        'resulted_by' => $revision->resultedBy?->name,
                        'superseded_at' => $revision->superseded_at?->toIso8601String(),
                        'superseded_by' => $revision->supersededBy?->name,
                        'reason' => $revision->reason,
                        'report' => $richText->toSafeHtml($revision->result_value),
                        'notes' => $richText->toSafeHtml($revision->result_notes),
                    ])->values()->all(),
                    'notes' => $richText->toSafeHtml($item->result_notes),
                    // Construite ici, jamais dans Vue : la feuille imprimable
                    // n'existe que pour l'imagerie, et un UUID d'analyse sur
                    // cette route ne désignerait rien.
                    'print_url' => $kind === 'imaging' && $item->resulted_at !== null
                        ? "/medicine/imaging-requests/{$item->uuid}/compte-rendu"
                        : null,
                    // Le même document que l'impression : ce que le médecin
                    // relit à l'écran est ce que la famille emporte.
                    'document' => $kind === 'imaging' && $item->resulted_at !== null
                        ? ImagingReportDocument::for($item->setRelation('imagingRequest', $request), $richText)
                        : null,
                    // La première saisie reste unique : `RecordImagingResultAction`
                    // refuse un second compte rendu, et une demande retirée
                    // n'attend plus rien. La correction est un autre acte
                    // (`can_correct`, ADR-130).
                    'can_record' => $canRecord
                        && $item->resulted_at === null
                        && $request->cancelled_at === null,
                    // Corriger n'existe qu'après la première saisie : jusque-là,
                    // c'est « Saisir le résultat ». Le serveur revérifie.
                    'can_correct' => $canCorrect
                        && $kind === 'imaging'
                        && $item->resulted_at !== null
                        && $request->cancelled_at === null,
                ])->values()->all(),
                // Le dernier résultat rendu : c'est lui qui décide si la
                // demande est récente ou rangée.
                'last_resulted_at' => $request->items
                    ->map($deliveredAt)
                    ->filter()
                    ->max()?->toIso8601String(),
                'results' => $request->items
                    ->filter(fn ($item) => $deliveredAt($item) !== null)
                    ->map(fn ($item) => [
                        'exam' => $item->catalog_item_name_snapshot,
                        'resulted_at' => $deliveredAt($item)?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                // Reprendre, jamais rouvrir une seconde consultation : le
                // lien ramène sur celle qui a émis la demande.
                // ADR-162 — une demande du séjour ramène au séjour.
                // Proposé seulement à qui peut l'ouvrir : un lien vers un refus
                // vaut moins qu'une absence de lien (ADR-146).
                'consultation_url' => match (true) {
                    $request->hospitalStay !== null => $viewer?->can('hospitalization.view') ? "/hospitalisation/{$request->hospitalStay->uuid}" : null,
                    // ADR-204 — une demande de la Maternité ramène à son dossier Maternité.
                    $request->maternityRecord !== null => $request->maternityRecord->orientation && $viewer?->can('maternity.view')
                        ? "/maternity/orientations/{$request->maternityRecord->orientation->uuid}"
                        : null,
                    default => $request->consultation?->orientation && $viewer?->can('consultations.view')
                        ? "/medicine/orientations/{$request->consultation->orientation->uuid}/paraclinique"
                        : null,
                },
                'from_stay' => $request->hospitalStay !== null,
                'from_maternity' => $request->maternityRecord !== null,
                'orientation_uuid' => $request->hospitalStay?->episodeOrientation?->uuid
                    ?? $request->maternityRecord?->orientation?->uuid
                    ?? $request->consultation?->orientation?->uuid,
                // Les mêmes conditions que `CancelParaclinicalRequestAction`
                // vérifie de son côté : l'écran n'est jamais la protection.
                // Ranger n'a de sens que pour ce qui est rendu et déjà lu :
                // une demande en attente est du travail, pas de l'archive.
                'can_archive' => $canArchive
                    && $request->displayStatus() === 'COMPLETED'
                    // Un résultat qui attend la validation du médecin n'est pas encore lu.
                    && ! ($isLab && $request->items->contains(fn ($item) => $item->awaitsApproval()))
                    && $request->archived_at === null
                    && $request->items->map($deliveredAt)->filter()->max()?->greaterThanOrEqualTo(now()->subDays(self::RECENT_DAYS)),
                // Ressortir n'a d'effet visible que si le résultat est récent :
                // un résultat ancien retourne aussitôt en archive tout seul.
                'can_unarchive' => $canArchive
                    && $request->archived_at !== null
                    && $request->items->map($deliveredAt)->filter()->max()?->greaterThanOrEqualTo(now()->subDays(self::RECENT_DAYS)),
                // Une demande du séjour se retire depuis le séjour.
                'can_withdraw' => $canWithdraw
                    && $request->consultation !== null
                    && $request->cancelled_at === null
                    && $request->consultation->isEditable()
                    && $request->consultation->orientation?->status === EpisodeOrientationStatus::InProgress
                    && ParaclinicalRequestPresenter::withdrawable($request),
            ]);
    }
}
