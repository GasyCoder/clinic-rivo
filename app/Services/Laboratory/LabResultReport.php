<?php

namespace App\Services\Laboratory;

use App\Enums\LabEntryMode;
use App\Enums\LabItemStatus;
use App\Models\AnalysisCatalog;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Support\Laboratory\LabEntryOptions;
use App\Support\Laboratory\LabReportDesign;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ADR-218 — le compte rendu de résultats d'analyses, tel que le laboratoire de
 * la clinique le remet (labo-vuejs) : une section par discipline (Hématologie,
 * Biochimie…) aux colonnes Résultat · Val. réf. · Antériorité, les lignes du
 * catalogue indentées, une valeur pathologique en gras, la note de chaque ligne
 * (« Notes : ») — la conclusion partielle —, puis la conclusion générale.
 *
 * Composé ici une seule fois : le PDF du laboratoire et celui du médecin lisent
 * la même structure, et la vue Blade ne calcule rien. Les valeurs, unités et
 * références sont celles figées à la saisie : rien n'est recalculé.
 *
 * `$viewer` est le médecin qui lit : ses antériorités ne reprennent que ce qui a
 * été envoyé, jamais une demande adressée à un confrère qu'il n'a pas ouverte
 * (ADR-216). Sans lecteur, c'est le laboratoire.
 *
 * `$approvedOnly` est la Réception (amendement ADR-216 quater) : seules des
 * analyses validées par le médecin, et des antériorités validées elles aussi.
 *
 * ADR-223 — l'aspect (modèle, couleurs, en-tête, QR, signataires, pied de page)
 * est celui que le portail a réglé pour ce site (LabReportDesign) ; il ne touche
 * jamais aux résultats.
 */
class LabResultReport
{
    private const ANTIBIOGRAM = ['S' => 'Sensible', 'I' => 'Intermédiaire', 'R' => 'Résistant'];

    /** Les lignes qui accompagnent le bloc final quand il faut changer de page. */
    private const CARRIED_ROWS = 6;

    /** En dessous, la dernière page — la signature et presque rien — est trop légère. */
    private const ROWS_WITH_CLOSING = 4;

    /** Ce qui reste au moins sous les résultats de la page qu'on quitte. */
    private const KEPT_ROWS = 3;

    /** Une analyse coupée garde plus que ces lignes (titre compris) au bas de la page. */
    private const ITEM_KEPT_ROWS = 2;

    /** Une ligne qui annonce les suivantes : jamais seule au bas d'une page. */
    private const LEADING_KINDS = ['title', 'heading', 'abg-title'];

    /** Une ligne qui complète la précédente (note, reprise) : jamais seule en haut d'une page. */
    private const TRAILING_KINDS = ['note'];

    public function __construct(
        private readonly LabWorkbench $workbench,
        private readonly LabDisciplines $disciplines,
        private readonly AppSettings $settings,
        private readonly LabResultRecipients $recipients,
    ) {}

    /**
     * @param  Collection<int, LabRequestItem>  $items  les analyses à imprimer, déjà choisies
     * @return array<string, mixed>
     */
    public function compose(LabRequest $request, Collection $items, ?User $viewer = null, bool $approvedOnly = false): array
    {
        $request->loadMissing(['episode.patient', 'requestedBy:id,name', 'conclusionBy:id,name', 'resultsRecipient:id,name', 'recipients:users.id,users.name']);
        $items = $items->sortBy('id')->values();
        $disciplines = $this->disciplines->forCatalogItems($items->pluck('catalog_item_id')->filter()->all());

        $sections = [];
        foreach ($items as $item) {
            $label = $disciplines[$item->catalog_item_id] ?? LabDisciplines::NONE;
            $sections[$label] ??= [
                'title' => $label === LabDisciplines::NONE ? 'ANALYSES' : mb_strtoupper($label),
                'items' => [],
            ];
            $sections[$label]['items'][] = $this->item($item, $viewer, $approvedOnly);
        }

        $validators = $items->filter(fn (LabRequestItem $item) => $item->validated_at !== null);
        $approvers = $items->filter(fn (LabRequestItem $item) => $item->isApproved());
        $approverNames = $approvers->map(fn (LabRequestItem $item) => $item->approvedBy?->name)->filter()->unique()->values()->all();
        $provisional = $viewer === null && $items->contains(fn (LabRequestItem $item) => $item->currentStatus() !== LabItemStatus::Validated);
        $design = (new LabReportDesign($this->settings))->resolve();

        return [
            'design' => $design,
            'site' => $this->site($design),
            'patient' => $this->patient($request),
            'request' => [
                'lab_number' => $request->lab_number,
                'episode_number' => $request->episode->episode_number,
                'requested_at' => $this->date($request->requested_at),
                'prescriber' => $request->requestedBy?->name,
                'recipient' => $request->recipientNames(),
                'clinical_notes' => $request->notes,
            ],
            'sections' => array_values($sections),
            'conclusion' => $request->conclusion,
            'conclusion_by' => $request->conclusionBy?->name,
            'validation' => [
                'by' => $validators->map(fn (LabRequestItem $item) => $item->validatedBy?->name)->filter()->unique()->values()->all(),
                'at' => $this->date($validators->max('validated_at'), true),
            ],
            // Amendement ADR-216 quater — la validation du médecin, quand elle est donnée.
            'approval' => [
                'by' => $approverNames,
                'at' => $this->date($approvers->max('approved_at'), true),
                'awaiting' => $items->filter(fn (LabRequestItem $item) => $item->awaitsApproval())->count(),
            ],
            'provisional' => $provisional,
            'generated_at' => now()->format('d/m/Y H:i'),
            'signatories' => self::signatories($design, $this->requestingPhysician($request), $approverNames[0] ?? null),
            'qr' => $design['show_qr'] ? self::qr((string) ($request->lab_number ?? $request->episode->episode_number)) : null,
        ];
    }

    /**
     * ADR-223 — le compte rendu de l'aperçu des paramètres : patient et résultats
     * fictifs, écrits comme tels, avec l'aspect en cours de saisie (`$draft`) et
     * l'en-tête réel du site. Rien n'est lu dans un dossier, rien n'est enregistré.
     *
     * @param  array<string, mixed>|null  $draft
     * @return array<string, mixed>
     */
    public function sample(?array $draft = null): array
    {
        $design = (new LabReportDesign($this->settings))->resolve($draft);
        $today = now();
        $earlier = $today->copy()->subWeeks(3)->format('d/m/Y');
        $result = fn (string $designation, string $value, string $reference, ?string $anteriority = null, ?string $flag = null, bool $critical = false, int $depth = 1) => [
            'kind' => 'result', 'depth' => $depth, 'designation' => $designation, 'bold' => false,
            'value' => $value, 'pathological' => $flag !== null || $critical, 'critical' => $critical, 'flag' => $flag,
            'reference' => $reference, 'anteriority' => $anteriority !== null ? "{$anteriority} ({$earlier})" : null,
        ];
        $analysis = fn (string $name, array $rows, bool $titleRow = true) => [
            'name' => $name, 'title_row' => $titleRow, 'rows' => $rows, 'text' => null,
            'sent_out' => null, 'provisional' => false, 'in_correction' => null,
        ];
        $physician = 'Dr EXEMPLE Médecin';

        return [
            'design' => $design,
            'sample' => true,
            'site' => $this->site($design),
            'patient' => ['name' => 'Mme EXEMPLE Patiente', 'number' => 'EX-26-0001', 'since' => $today->copy()->subYear()->format('d/m/Y'),
                'birth_date' => $today->copy()->subYears(28)->format('d-m-Y'), 'age' => 28, 'sex' => 'Féminin'],
            'request' => ['lab_number' => 'EX-L26-00001', 'episode_number' => 'EX-26-0001-01', 'requested_at' => $today->format('d/m/Y'),
                'prescriber' => $physician, 'recipient' => $physician, 'clinical_notes' => 'Bilan de contrôle (exemple).'],
            'sections' => [
                ['title' => 'HEMATOLOGIE', 'items' => [$analysis('Numération formule sanguine', [
                    ['kind' => 'heading', 'depth' => 0, 'designation' => 'Hémogramme', 'bold' => true],
                    $result('Hématies', '4,52 T/L', '4 - 5,4', '4,61 T/L'),
                    $result('Hémoglobine', '11,2 g/dL', '12 - 16', '12,1 g/dL', 'LOW'),
                    $result('Hématocrite', '38 %', '37 - 47', '39 %'),
                    $result('Leucocytes', '7,8 G/L', '4 - 10', '6,9 G/L'),
                    $result('Plaquettes', '265 G/L', '150 - 400'),
                    ['kind' => 'note', 'depth' => 1, 'text' => 'Exemple de conclusion partielle d’une ligne.'],
                ])]],
                ['title' => 'BIOCHIMIE', 'items' => [
                    $analysis('Glycémie à jeun', [$result('Glycémie à jeun', '1,32 g/L', '0,70 - 1,10', '1,05 g/L', 'HIGH', depth: 0)], false),
                    $analysis('Kaliémie', [$result('Kaliémie', '6,8 mmol/L', '3,5 - 5,0', null, 'HIGH', true, 0)], false),
                    $analysis('Créatinine', [$result('Créatinine', '9 mg/L', '6 - 12', '8 mg/L', depth: 0)], false),
                ]],
            ],
            'conclusion' => 'Exemple de conclusion générale du laboratoire.',
            'conclusion_by' => null,
            'validation' => ['by' => ['EXEMPLE Technicien'], 'at' => $today->format('d/m/Y').' 09:30'],
            'approval' => ['by' => [$physician], 'at' => $today->format('d/m/Y').' 11:15', 'awaiting' => 0],
            'provisional' => false,
            'generated_at' => $today->format('d/m/Y H:i'),
            'signatories' => self::signatories($design, $physician, $physician),
            'qr' => $design['show_qr'] ? self::qr('EX-L26-00001') : null,
        ];
    }

    /**
     * ADR-223 — qui signe, écrit une seule fois : le laboratoire, un médecin (celui
     * qui a validé, quand il l'a fait), les deux, ou — automatique — le médecin qui a
     * demandé l'analyse ; à défaut de médecin, le laboratoire.
     *
     * @return list<array{label: string, name: ?string}>
     */
    public static function signatories(array $design, ?string $requestingPhysician, ?string $approver): array
    {
        $lab = ['label' => $design['lab_signatory'], 'name' => null];
        $physician = fn (?string $name) => ['label' => $design['physician_signatory'], 'name' => $name];

        return match ($design['signatory']) {
            'PHYSICIAN' => [$physician($approver)],
            'BOTH' => [$lab, $physician($approver)],
            'AUTO' => $requestingPhysician !== null ? [$physician($requestingPhysician)] : [$lab],
            default => [$lab],
        };
    }

    /**
     * Le médecin qui a demandé l'analyse : le prescripteur quand il peut recevoir des
     * résultats (un médecin, ADR-216) ; une demande de l'accueil n'a pas de médecin
     * prescripteur, c'est alors le premier médecin à qui les résultats sont adressés.
     */
    private function requestingPhysician(LabRequest $request): ?string
    {
        if ($this->recipients->isRecipient($request->requestedBy)) {
            return $request->requestedBy->name;
        }

        return $request->resultsRecipient?->name ?? $request->recipients->first()?->name;
    }

    /** Le QR du numéro de laboratoire — et de rien d'autre : le scanner rouvre la demande (ADR-214). */
    private static function qr(string $text): ?string
    {
        if ($text === '' || ! extension_loaded('gd')) {
            return null;
        }

        return (new QRCode(new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ])))->render($text);
    }

    /**
     * Le PDF, avec « Page n / N » au bas de chaque page.
     *
     * Le bloc final (qui a envoyé, qui a validé, la signature) ne part jamais seul
     * sur une page : s'il ne tient pas sous les derniers résultats, le compte rendu
     * est d'abord resserré pour qu'il y tienne — souvent une page au lieu de deux —,
     * puis, si le compte rendu est trop long pour cela, ses dernières lignes
     * descendent avec lui sur la page suivante.
     */
    public function render(array $report): string
    {
        $dompdf = $this->paginate($report)['dompdf'];
        $design = $report['design'] ?? [];

        if ($design['show_page_numbers'] ?? true) {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont(($design['font'] ?? 'SANS') === 'SERIF' ? 'DejaVu Serif' : 'DejaVu Sans');
            $canvas->page_text($canvas->get_width() - 80, $canvas->get_height() - 24, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.6, 0.6, 0.6]);
        }

        return (string) $dompdf->output();
    }

    /**
     * La mise en page retenue, parmi l'ordinaire, la resserrée et celles où les
     * dernières lignes sont reportées : jamais le bloc final seul, puis le moins
     * de pages, puis une dernière page qui porte assez de résultats, puis, à égalité,
     * la mise en page ordinaire.
     *
     * @return array{dompdf: Dompdf, layout: array<string, mixed>, rows: array<int, array{page: int, kind: string, section: int, item: int}>, pages: int, closing: ?int, orphaned: bool, balanced: bool}
     */
    public function paginate(array $report): array
    {
        $normal = $this->layout($report);
        if ($normal['balanced']) {
            return $normal;
        }

        $compact = $this->layout($report, ['compact' => true]);
        if ($compact['balanced'] && $compact['pages'] < $normal['pages']) {
            return $compact;
        }

        $candidates = [$normal, $compact];
        $bases = $compact['pages'] < $normal['pages'] ? [$normal, $compact] : [$normal];
        foreach ($bases as $base) {
            if (($carry = $this->carry($base)) !== null) {
                $candidates[] = $this->layout($report, $carry + $base['layout']);
            }
        }

        usort($candidates, fn (array $a, array $b) => [$a['orphaned'], $a['pages'], ! $a['balanced'], $a['layout']['compact'] ?? false]
            <=> [$b['orphaned'], $b['pages'], ! $b['balanced'], $b['layout']['compact'] ?? false]);

        return $candidates[0];
    }

    /**
     * Un rendu, et où chaque ligne de résultats, la conclusion et le bloc final
     * sont tombés : la vue les marque (`data-row`, `#pdf-conclusion`, `#pdf-closing`).
     *
     * @param  array{compact?: bool, break_before?: int, break_section?: int}  $layout
     * @return array{dompdf: Dompdf, layout: array<string, mixed>, rows: array<int, array{page: int, kind: string, section: int, item: int}>, pages: int, closing: ?int, orphaned: bool, balanced: bool}
     */
    private function layout(array $report, array $layout = []): array
    {
        $dompdf = Pdf::loadView('pdf.laboratory.results', ['report' => $report, 'layout' => $layout])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true) // seules les lettres utilisées : un PDF léger
            ->getDomPDF();

        $rows = [];
        $conclusion = null;
        $closing = null;
        $dompdf->setCallbacks([[
            'event' => 'begin_frame',
            'f' => function (Frame $frame, Canvas $canvas) use (&$rows, &$conclusion, &$closing): void {
                $node = $frame->get_node();
                if (! $node instanceof \DOMElement) {
                    return;
                }
                $page = $canvas->get_page_number();
                if ($node->hasAttribute('data-row')) {
                    $rows[(int) $node->getAttribute('data-row')] ??= [
                        'page' => $page,
                        'kind' => $node->getAttribute('data-kind'),
                        'section' => (int) $node->getAttribute('data-section'),
                        'item' => (int) $node->getAttribute('data-item'),
                    ];
                } elseif ($node->getAttribute('id') === 'pdf-conclusion') {
                    $conclusion ??= $page;
                } elseif ($node->getAttribute('id') === 'pdf-closing') {
                    $closing ??= $page;
                }
            },
        ]]);
        $dompdf->render();
        ksort($rows);

        $pages = $dompdf->getCanvas()->get_page_count();
        $lastContent = max([0, $conclusion ?? 0, ...array_column($rows, 'page')]);
        // Rien au-dessus de lui sur sa page : le bloc final est parti seul.
        $orphaned = $closing !== null && $lastContent > 0 && $closing > $lastContent;
        $withClosing = count(array_filter($rows, fn (array $row) => $row['page'] === $closing));

        return [
            'dompdf' => $dompdf,
            'layout' => $layout,
            'rows' => $rows,
            'pages' => $pages,
            'closing' => $closing,
            'orphaned' => $orphaned,
            // Une dernière page qui ne porte qu'une ou deux lignes avec la signature est
            // à peine mieux : la conclusion générale, elle, suffit à la remplir.
            'balanced' => ! $orphaned && ($pages === 1 || $closing === null || $conclusion === $closing
                || $withClosing >= self::ROWS_WITH_CLOSING),
        ];
    }

    /**
     * Où forcer le saut de page pour que des lignes accompagnent le bloc final :
     * les dernières de la page qui précède la sienne, sans séparer un titre de ce
     * qu'il annonce ni une note de sa ligne. `null` quand cette page n'en a pas
     * assez pour en céder sans se vider.
     *
     * @param  array{rows: array<int, array{page: int, kind: string, section: int, item: int}>, closing: ?int}  $measure
     * @return array{break_before?: int, break_section?: int}|null
     */
    private function carry(array $measure): ?array
    {
        $rows = $measure['rows'];
        $closing = $measure['closing'];
        if ($closing === null || $closing < 2) {
            return null;
        }
        $already = count(array_filter($rows, fn (array $row) => $row['page'] === $closing));
        $donor = array_keys(array_filter($rows, fn (array $row) => $row['page'] === $closing - 1));
        if (count($donor) < 2 || $already >= self::CARRIED_ROWS) {
            return null;
        }

        $index = count($donor) - max(1, min(self::CARRIED_ROWS - $already, count($donor) - self::KEPT_ROWS));
        while ($index > 0 && (in_array($rows[$donor[$index - 1]]['kind'], self::LEADING_KINDS, true)
            || in_array($rows[$donor[$index]]['kind'], self::TRAILING_KINDS, true))) {
            $index--;
        }
        // Une analyse ne laisse pas son titre et une seule ligne au bas de la page : elle descend entière.
        $start = $index;
        while ($start > 0 && $rows[$donor[$start - 1]]['item'] === $rows[$donor[$index]]['item']) {
            $start--;
        }
        if ($start > 0 && $index - $start <= self::ITEM_KEPT_ROWS) {
            $index = $start;
        }
        if ($index === 0) {
            return null;
        }

        $first = $donor[$index];
        $section = $rows[$first]['section'];

        // Première ligne d'une section : c'est la section entière qui change de page, titre compris.
        return $rows[$donor[$index - 1]]['section'] !== $section
            ? ['break_section' => $section]
            : ['break_before' => $first];
    }

    /** Le nom du fichier : le patient et le numéro de laboratoire, lisibles. */
    public function filename(LabRequest $request): string
    {
        $patient = $request->episode->patient;
        $name = trim(implode(' ', array_filter([$patient->last_name, $patient->first_name])));
        $parts = array_filter(['Resultats', $request->lab_number ?? $request->episode->episode_number, $name]);
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii(implode(' ', $parts)));

        return trim((string) $slug, '-').'.pdf';
    }

    /** @return array<string, mixed> */
    private function item(LabRequestItem $item, ?User $viewer, bool $approvedOnly = false): array
    {
        $item->loadMissing(['results', 'notes', 'antibiograms.results', 'validatedBy:id,name', 'approvedBy:id,name', 'resultedBy:id,name']);
        $definitions = $this->workbench->definitions($item);
        $results = $item->results->keyBy('analysis_catalog_id');
        $notes = $item->notes->keyBy('analysis_catalog_id');
        // ADR-216 — reprise pour être refaite : le médecin garde la valeur envoyée, jamais la saisie qui la remplace.
        $inCorrection = $viewer !== null && $item->currentStatus() !== LabItemStatus::Validated;
        $structured = ! $inCorrection && $definitions->isNotEmpty() && $item->results->contains(fn (LabResult $result) => ! $result->isBlank());
        $anteriority = $structured
            ? $this->workbench->anteriority($item, $this->workbench->patient($item), $definitions->pluck('analysis.id')->all(), $viewer, $approvedOnly)
            : [];

        $rows = $structured ? $this->rows($item, $definitions, $results, $notes, $anteriority) : [];
        // ADR-219 — plus de conclusion par analyse (la conclusion générale suffit) : une
        // conclusion saisie avant ce choix reste imprimée, comme une note de l'analyse.
        if (filled($item->conclusion)) {
            $rows[] = ['kind' => 'note', 'depth' => 0, 'text' => (string) $item->conclusion];
        }
        // Une seule racine au catalogue : c'est elle le titre de l'analyse (« HEMOGRAMME »).
        $roots = $definitions->where('depth', 0)->count();

        return [
            'name' => $item->catalog_item_name_snapshot,
            'title_row' => ! $structured || $roots !== 1,
            'rows' => $rows,
            'text' => $structured ? null : trim(implode("\n", array_filter([$item->result_value, $item->result_notes]))),
            'sent_out' => $item->sent_out_at ? $item->external_lab_name : null,
            'provisional' => ! $inCorrection && $item->currentStatus() !== LabItemStatus::Validated,
            'in_correction' => $inCorrection ? ($item->return_reason ?: true) : null,
        ];
    }

    /**
     * Les lignes imprimées, dans l'ordre de l'arbre : un groupe ou un intitulé
     * n'est imprimé que si une ligne qui le suit porte un résultat.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(LabRequestItem $item, Collection $definitions, Collection $results, Collection $notes, array $anteriority): array
    {
        $list = $definitions->values();
        $filled = $list->map(fn (array $row) => ($result = $results->get($row['analysis']->id)) !== null && ! $result->isBlank());
        $hasResultIn = function (int $index) use ($list, $filled): bool {
            $depth = $list[$index]['depth'];
            $mode = LabEntryMode::for($list[$index]['analysis']);
            // Un groupe regarde ses enfants ; un intitulé seul (« Soit ») regarde ses frères qui suivent.
            $stop = $list[$index]['analysis']->level === AnalysisCatalog::CONTAINER_LEVEL || $mode === LabEntryMode::Label ? $depth : $depth - 1;
            for ($next = $index + 1; $next < $list->count() && $list[$next]['depth'] > $stop; $next++) {
                if ($filled[$next]) {
                    return true;
                }
            }

            return false;
        };

        $rows = [];
        $pendingNotes = []; // note d'un groupe : imprimée après sa dernière ligne
        foreach ($list as $index => $row) {
            /** @var AnalysisCatalog $analysis */
            $analysis = $row['analysis'];
            $depth = $row['depth'];
            $result = $results->get($analysis->id);
            $note = $notes->get($analysis->id)?->note;

            foreach (array_keys($pendingNotes) as $groupDepth) {
                if ($depth <= $groupDepth) {
                    $rows[] = $pendingNotes[$groupDepth];
                    unset($pendingNotes[$groupDepth]);
                }
            }

            $isGroup = $analysis->level === AnalysisCatalog::CONTAINER_LEVEL || ! LabEntryMode::for($analysis)->takesResult();

            if ($isGroup) {
                if (! $hasResultIn($index)) {
                    continue;
                }
                $rows[] = ['kind' => 'heading', 'depth' => $depth, 'designation' => $analysis->designation, 'bold' => (bool) $analysis->is_bold];
                if (filled($note)) {
                    $pendingNotes[$depth] = ['kind' => 'note', 'depth' => $depth + 1, 'text' => $note];
                }

                continue;
            }

            if ($result === null || $result->isBlank()) {
                continue;
            }

            $rows[] = [
                'kind' => 'result',
                'depth' => $depth,
                'designation' => $result->designation_snapshot,
                'bold' => (bool) $analysis->is_bold,
                'value' => $this->value($item, $result),
                'pathological' => $result->interpretation === 'PATHOLOGICAL',
                'critical' => (bool) $result->is_critical,
                'flag' => $result->range_flag,
                'reference' => $result->reference_snapshot,
                'anteriority' => $this->anteriorityText($anteriority[$analysis->id] ?? null),
            ];

            if ($result->entry_mode === LabEntryMode::Culture->value) {
                foreach ($item->antibiograms->where('analysis_catalog_id', $analysis->id) as $antibiogram) {
                    $rows[] = [
                        'kind' => 'antibiogram',
                        'depth' => $depth + 1,
                        'bacterium' => $antibiogram->bacterium_name_snapshot,
                        'notes' => $antibiogram->notes,
                        'lines' => $antibiogram->results->sortBy(fn ($line) => [array_search($line->interpretation, ['R', 'I', 'S'], true), $line->antibiotic_name_snapshot])
                            ->map(fn ($line) => [
                                'antibiotic' => $line->antibiotic_name_snapshot,
                                'label' => self::ANTIBIOGRAM[$line->interpretation] ?? $line->interpretation,
                                'resistant' => $line->interpretation === 'R',
                                'intermediate' => $line->interpretation === 'I',
                                'measure' => $line->measure !== null ? rtrim(rtrim(number_format((float) $line->measure, 2, ',', ''), '0'), ',').' '.$line->measure_unit : null,
                            ])->values()->all(),
                    ];
                }
            }

            if (filled($note)) {
                $rows[] = ['kind' => 'note', 'depth' => $depth + 1, 'text' => $note];
            }
        }

        foreach (array_reverse($pendingNotes, true) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function value(LabRequestItem $item, LabResult $result): string
    {
        $selections = $result->selections ?? [];
        $text = match ($result->entry_mode) {
            LabEntryMode::Culture->value => (LabEntryOptions::CULTURE[$result->value] ?? (string) $result->value)
                .($result->value === LabEntryOptions::CULTURE_OTHER && filled($selections['other'] ?? null) ? ' — '.$selections['other'] : ''),
            LabEntryMode::Nugent->value => $result->value !== null
                ? "Score {$result->value}/10 — ".LabEntryOptions::nugent((int) $result->value)['label']
                : 'Score incomplet',
            default => trim((string) $result->value.(filled($selections['detail'] ?? null) ? ' — '.$selections['detail'] : '')),
        };

        if ($result->entry_mode === LabEntryMode::Numeric->value) {
            $text = self::decimal($text).(filled($result->unit_snapshot) ? ' '.$result->unit_snapshot : '');
        }

        return $text;
    }

    /** « 9.8 » s'écrit « 9,8 » sur un compte rendu, comme à la saisie. */
    private static function decimal(string $value): string
    {
        return preg_match('/^-?\d+\.\d+$/', $value) ? str_replace('.', ',', $value) : $value;
    }

    /** @param  array<string, mixed>|null  $previous */
    private function anteriorityText(?array $previous): ?string
    {
        if ($previous === null || blank($previous['value'] ?? null)) {
            return null;
        }
        $value = match ($previous['entry_mode'] ?? null) {
            LabEntryMode::Culture->value => LabEntryOptions::CULTURE[$previous['value']] ?? (string) $previous['value'],
            LabEntryMode::Nugent->value => "Score {$previous['value']}/10",
            default => (string) $previous['value'],
        };
        if (($previous['entry_mode'] ?? null) === LabEntryMode::Numeric->value) {
            $value = self::decimal($value).(filled($previous['unit']) ? ' '.$previous['unit'] : '');
        }
        $date = $this->date($previous['resulted_at'] ?? null);

        return $date ? "{$value} ({$date})" : $value;
    }

    /** @return array<string, mixed> */
    private function patient(LabRequest $request): array
    {
        $patient = $request->episode->patient;
        $civility = $patient->civility?->label();
        $name = trim(implode(' ', array_filter([mb_strtoupper((string) $patient->last_name), $patient->first_name])));

        return [
            'name' => trim(($civility ? "{$civility} " : '').$name),
            'number' => $patient->patient_number,
            'since' => $this->date($patient->created_at),
            'birth_date' => $patient->birth_date?->format('d-m-Y'),
            'age' => $patient->birth_date?->age ?? $patient->declared_age,
            'sex' => match ($patient->sex?->value) {
                'M' => 'Masculin', 'F' => 'Féminin', default => null
            },
        ];
    }

    /**
     * L'en-tête et le pied : ceux du site (ADR-184). Le logo est celui du compte
     * rendu quand il en a un (ADR-223), sinon celui du site ; aucun s'il est masqué.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    private function site(array $design): array
    {
        $documents = $this->settings->documents();
        $name = (string) config('rivo.site.name', '');

        return [
            'brand' => $this->settings->brand(),
            'site' => $name,
            'logo' => $design['show_logo'] ? $this->logo() : null,
            'color' => $this->settings->primaryColor() ?: '#1d4ed8',
            'nif' => $documents['nif'] ?? null,
            'stat' => $documents['stat'] ?? null,
            'address' => $documents['address'] ?? null,
            'phone' => $documents['phone'] ?? null,
            'email' => $documents['email'] ?? null,
        ];
    }

    /**
     * Le logo en donnée intégrée : dompdf ne va pas chercher d'adresse. Ramené à
     * 600 px de large, sinon un logo d'origine alourdit chaque PDF de plusieurs Mo.
     */
    private function logo(): ?string
    {
        $uri = $this->settings->assetDataUri('lab_logo') ?? $this->settings->assetDataUri('logo');
        if ($uri === null) {
            $url = (string) config('rivo.documents.logo_url');
            $path = str_starts_with($url, '/') ? public_path(ltrim($url, '/')) : null;
            if ($path === null || ! is_file($path)) {
                return null;
            }
            $mime = mime_content_type($path) ?: 'image/png';
            $uri = "data:{$mime};base64,".base64_encode((string) file_get_contents($path));
        }

        if (! preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,(.+)$#s', $uri, $match)) {
            return null; // un SVG ou autre : dompdf le rendrait mal
        }

        return $this->shrink($match[1], (string) base64_decode($match[2])) ?? $uri;
    }

    private function shrink(string $mime, string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring') || ($image = @imagecreatefromstring($bytes)) === false) {
            return null;
        }
        $width = imagesx($image);
        if ($width <= 600) {
            return null;
        }
        $height = (int) round(imagesy($image) * 600 / $width);
        $small = imagecreatetruecolor(600, $height);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 600, $height, $width, imagesy($image));
        ob_start();
        imagepng($small, null, 9);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function date(mixed $value, bool $withTime = false): ?string
    {
        if ($value === null) {
            return null;
        }
        $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return $date->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    }
}
