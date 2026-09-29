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
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
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
 */
class LabResultReport
{
    private const ANTIBIOGRAM = ['S' => 'Sensible', 'I' => 'Intermédiaire', 'R' => 'Résistant'];

    public function __construct(
        private readonly LabWorkbench $workbench,
        private readonly LabDisciplines $disciplines,
        private readonly AppSettings $settings,
    ) {}

    /**
     * @param  Collection<int, LabRequestItem>  $items  les analyses à imprimer, déjà choisies
     * @return array<string, mixed>
     */
    public function compose(LabRequest $request, Collection $items, ?User $viewer = null): array
    {
        $request->loadMissing(['episode.patient', 'requestedBy:id,name', 'conclusionBy:id,name', 'resultsRecipient:id,name']);
        $items = $items->sortBy('id')->values();
        $disciplines = $this->disciplines->forCatalogItems($items->pluck('catalog_item_id')->filter()->all());

        $sections = [];
        foreach ($items as $item) {
            $label = $disciplines[$item->catalog_item_id] ?? LabDisciplines::NONE;
            $sections[$label] ??= [
                'title' => $label === LabDisciplines::NONE ? 'ANALYSES' : mb_strtoupper($label),
                'items' => [],
            ];
            $sections[$label]['items'][] = $this->item($item, $viewer);
        }

        $validators = $items->filter(fn (LabRequestItem $item) => $item->validated_at !== null);
        $provisional = $viewer === null && $items->contains(fn (LabRequestItem $item) => $item->currentStatus() !== LabItemStatus::Validated);

        return [
            'site' => $this->site(),
            'patient' => $this->patient($request),
            'request' => [
                'lab_number' => $request->lab_number,
                'episode_number' => $request->episode->episode_number,
                'requested_at' => $this->date($request->requested_at),
                'prescriber' => $request->requestedBy?->name,
                'recipient' => $request->resultsRecipient?->name,
                'clinical_notes' => $request->notes,
            ],
            'sections' => array_values($sections),
            'conclusion' => $request->conclusion,
            'conclusion_by' => $request->conclusionBy?->name,
            'validation' => [
                'by' => $validators->map(fn (LabRequestItem $item) => $item->validatedBy?->name)->filter()->unique()->values()->all(),
                'at' => $this->date($validators->max('validated_at'), true),
            ],
            'provisional' => $provisional,
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    /** Le PDF, avec « Page n / N » au bas de chaque page. */
    public function render(array $report): string
    {
        $pdf = Pdf::loadView('pdf.laboratory.results', ['report' => $report])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true); // seules les lettres utilisées : un PDF léger
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 80, $canvas->get_height() - 24, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.6, 0.6, 0.6]);

        return (string) $dompdf->output();
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
    private function item(LabRequestItem $item, ?User $viewer): array
    {
        $item->loadMissing(['results', 'notes', 'antibiograms.results', 'validatedBy:id,name', 'resultedBy:id,name']);
        $definitions = $this->workbench->definitions($item);
        $results = $item->results->keyBy('analysis_catalog_id');
        $notes = $item->notes->keyBy('analysis_catalog_id');
        // ADR-216 — reprise pour être refaite : le médecin garde la valeur envoyée, jamais la saisie qui la remplace.
        $inCorrection = $viewer !== null && $item->currentStatus() !== LabItemStatus::Validated;
        $structured = ! $inCorrection && $definitions->isNotEmpty() && $item->results->contains(fn (LabResult $result) => ! $result->isBlank());
        $anteriority = $structured
            ? $this->workbench->anteriority($item, $this->workbench->patient($item), $definitions->pluck('analysis.id')->all(), $viewer)
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

    /** @return array<string, mixed> L'en-tête et le pied : ceux du site (ADR-184). */
    private function site(): array
    {
        $documents = $this->settings->documents();
        $name = (string) config('rivo.site.name', '');

        return [
            'brand' => $this->settings->brand(),
            'site' => $name,
            'logo' => $this->logo(),
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
        $uri = $this->settings->assetDataUri('logo');
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
