<?php

namespace App\Http\Controllers\Medicine;

use App\Enums\EpisodeOrientationStatus;
use App\Http\Controllers\Controller;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Support\ImagingReportTemplates;
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
 * Il n'existe volontairement pas de statut « terminée » distinct de
 * « résultat disponible » : rien n'enregistre qu'un médecin a pris
 * connaissance d'un résultat, et l'inventer ferait afficher une lecture que
 * personne n'a faite.
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
     *   active    ce qui attend encore quelque chose
     *   recent    ce qui vient d'arriver et n'a peut-être pas été lu
     *   archived  ce qui est rangé — rendu il y a longtemps, ou retiré
     */
    private const FILTERS = ['active', 'recent', 'archived'];

    /**
     * La frontière entre « récent » et « archivé ».
     *
     * Aucune règle du CDC ne la fixe : c'est un confort de lecture, jamais
     * une règle clinique. Rien n'est masqué pour autant — un résultat plus
     * ancien reste consultable dans « Archivées » et par la recherche.
     */
    private const RECENT_DAYS = 7;

    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true)
            ? (string) $request->query('filter')
            // « Active » par défaut : c'est le travail en cours, pas
            // l'historique, que le médecin vient chercher ici.
            : 'active';
        $search = trim((string) $request->query('q', ''));

        $canViewLab = $request->user()->can('laboratory_orders.view');
        $canViewImaging = $request->user()->can('imaging_orders.view');
        // Seule l'imagerie est saisissable ici : un résultat d'analyse
        // appartient au Laboratoire, qui possède `laboratory_results.create`
        // et son propre écran. Médecine n'y touche jamais.
        $canRecordImaging = $request->user()->can('imaging_results.create');
        // Retirer, jamais supprimer (ADR-010) : la demande garde son
        // auteur, sa date et son numéro, et reste lisible au dossier.
        $canWithdraw = $request->user()->can('consultations.update');

        $rows = collect()
            ->concat($canViewLab ? $this->rows(LabRequest::query(), 'lab', 'Laboratoire', false, $canWithdraw) : [])
            ->concat($canViewImaging ? $this->rows(ImagingRequest::query(), 'imaging', 'Imagerie', $canRecordImaging, $canWithdraw) : []);

        $counts = collect(self::FILTERS)
            ->mapWithKeys(fn (string $key) => [$key => $rows->filter($this->group($key))->count()])
            ->all();

        $visible = $rows
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
            'filters' => ['filter' => $filter, 'q' => $search],
            // `paraclinical_requests.view` ouvre l'écran ; ces deux-là
            // décident de ce qu'on y voit. Sans elles la liste est vide, et
            // un vide muet se lit « aucune demande » — l'écran doit dire que
            // c'est un droit qui manque, pas du travail terminé.
            'can' => ['lab' => $canViewLab, 'imaging' => $canViewImaging],
            // ADR-108 — les feuilles de la clinique, servies à l'écran de
            // saisie. Elles n'intéressent que qui peut écrire un compte
            // rendu : les envoyer à un compte qui ne fait que consulter
            // remplirait le payload d'un canevas qu'il ne verra jamais.
            'report_templates' => $canViewImaging ? ImagingReportTemplates::all() : [],
        ]);
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

        return match ($filter) {
            'active' => fn (array $row): bool => in_array($row['status'], ['REQUESTED', 'IN_PROGRESS'], true),
            'recent' => fn (array $row): bool => $row['status'] === 'COMPLETED'
                && $row['last_resulted_at'] !== null
                && Carbon::parse($row['last_resulted_at'])->greaterThanOrEqualTo($threshold),
            // Tout le reste : les demandes retirées, et les résultats rendus
            // il y a plus longtemps. Rien ne disparaît, tout se range.
            default => fn (array $row): bool => $row['status'] === 'CANCELLED'
                || ($row['status'] === 'COMPLETED'
                    && ($row['last_resulted_at'] === null
                        || Carbon::parse($row['last_resulted_at'])->lessThan($threshold))),
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<LabRequest|ImagingRequest>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function rows($query, string $kind, string $familyLabel, bool $canRecord = false, bool $canWithdraw = false): Collection
    {
        $richText = app(ClinicalRichTextSanitizer::class);

        return $query
            ->with([
                'items',
                'items.resultedBy:id,name',
                'requestedBy:id,name',
                'consultation:id,episode_orientation_id,status,completed_at',
                'consultation.orientation:id,uuid,status',
                'episode:id,uuid,episode_number,patient_id',
                'episode.patient:id,uuid,first_name,last_name,patient_number',
            ])
            ->latest('requested_at')
            ->get()
            // Une demande orpheline de son passage ou de son patient ne
            // décrit plus rien d'exploitable : on l'écarte de l'écran
            // plutôt que d'y afficher des tirets.
            ->filter(fn ($request) => $request->episode?->patient !== null && $request->consultation !== null)
            ->map(fn ($request) => [
                'uuid' => $request->uuid,
                'kind' => $kind,
                'family_label' => $familyLabel,
                'status' => $request->displayStatus(),
                'requested_at' => $request->requested_at?->toIso8601String(),
                'requested_by' => $request->requestedBy?->name,
                'cancelled_at' => $request->cancelled_at?->toIso8601String(),
                'cancel_reason' => $request->cancel_reason,
                'notes' => $request->notes,
                'episode_number' => $request->episode->episode_number,
                'patient' => [
                    'uuid' => $request->episode->patient->uuid,
                    'name' => trim($request->episode->patient->first_name.' '.$request->episode->patient->last_name),
                    'number' => $request->episode->patient->patient_number,
                ],
                'exams' => $request->items->pluck('catalog_item_name_snapshot')->all(),
                // Ligne par ligne : c'est l'examen qui porte son compte rendu,
                // pas la demande. Une demande de deux examens peut n'en avoir
                // qu'un de rendu.
                'items' => $request->items->map(fn ($item) => [
                    // UUID, jamais l'id SQL : c'est l'identifiant que la
                    // route lie et le seul exposé (ADR-005).
                    'uuid' => $item->uuid,
                    'exam' => $item->catalog_item_name_snapshot,
                    'resulted_at' => $item->resulted_at?->toIso8601String(),
                    'resulted_by' => $item->resultedBy?->name,
                    'report' => $richText->toSafeHtml($item->result_value),
                    'notes' => $richText->toSafeHtml($item->result_notes),
                    // Construite ici, jamais dans Vue : la feuille imprimable
                    // n'existe que pour l'imagerie, et un UUID d'analyse sur
                    // cette route ne désignerait rien.
                    'print_url' => $kind === 'imaging' && $item->resulted_at !== null
                        ? "/medicine/imaging-requests/{$item->uuid}/compte-rendu"
                        : null,
                    // Jamais réécrit : `RecordImagingResultAction` refuse un
                    // second compte rendu, et une demande retirée n'attend
                    // plus rien.
                    'can_record' => $canRecord
                        && $item->resulted_at === null
                        && $request->cancelled_at === null,
                ])->values()->all(),
                // Le dernier résultat rendu : c'est lui qui décide si la
                // demande est récente ou rangée.
                'last_resulted_at' => $request->items
                    ->pluck('resulted_at')
                    ->filter()
                    ->max()?->toIso8601String(),
                'results' => $request->items
                    ->filter(fn ($item) => $item->resulted_at !== null)
                    ->map(fn ($item) => [
                        'exam' => $item->catalog_item_name_snapshot,
                        'resulted_at' => $item->resulted_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                // Reprendre, jamais rouvrir une seconde consultation : le
                // lien ramène sur celle qui a émis la demande.
                'consultation_url' => $request->consultation->orientation
                    ? "/medicine/orientations/{$request->consultation->orientation->uuid}/paraclinique"
                    : null,
                'orientation_uuid' => $request->consultation->orientation?->uuid,
                // Les mêmes conditions que `CancelParaclinicalRequestAction`
                // vérifie de son côté : l'écran n'est jamais la protection.
                'can_withdraw' => $canWithdraw
                    && $request->cancelled_at === null
                    && $request->consultation->isEditable()
                    && $request->consultation->orientation?->status === EpisodeOrientationStatus::InProgress
                    && $request->items->every(fn ($item) => $item->resulted_at === null),
            ]);
    }
}
