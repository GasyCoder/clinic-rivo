<?php

namespace App\Services\Laboratory;

use App\Enums\LabEntryMode;
use App\Models\AnalysisCatalog;
use App\Models\LabAntibiogram;
use App\Models\LabBacteriumFamily;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\Patient;
use App\Support\Laboratory\LabReferenceRange;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * ADR-213 — ce que la paillasse lit pour une analyse demandée : l'arbre des
 * analyses du catalogue de sa prestation, chacune avec son mode de saisie, sa
 * référence pour ce patient, son résultat saisi et son antériorité.
 *
 * Écrit une seule fois : l'écran de saisie, l'actions d'enregistrement et la
 * feuille imprimée lisent le même arbre.
 */
class LabWorkbench
{
    public function __construct(private readonly AnalysisReferenceResolver $references) {}

    /**
     * Les analyses du catalogue de la prestation, dans l'ordre de l'arbre.
     * Une analyse désactivée depuis reste lue quand un résultat la porte.
     *
     * @return Collection<int, array{analysis: AnalysisCatalog, depth: int}>
     */
    public function definitions(LabRequestItem $item): Collection
    {
        $withResults = $item->results()->pluck('analysis_catalog_id');

        $analyses = AnalysisCatalog::withTrashed()
            ->where('catalog_item_id', $item->catalog_item_id)
            ->where(fn ($query) => $query
                ->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'))
                ->orWhereIn('id', $withResults))
            ->orderBy('display_order')
            ->orderBy('designation')
            ->get();

        $ids = $analyses->pluck('id')->all();
        $byParent = $analyses->groupBy(fn (AnalysisCatalog $analysis) => in_array($analysis->parent_id, $ids, true) ? $analysis->parent_id : 0);

        $ordered = collect();
        $walk = function (int $parentId, int $depth) use (&$walk, $byParent, $ordered): void {
            foreach ($byParent->get($parentId, collect()) as $analysis) {
                $ordered->push(['analysis' => $analysis, 'depth' => $depth]);
                if ($depth < 6) {
                    $walk($analysis->id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $ordered;
    }

    public function patient(LabRequestItem $item): Patient
    {
        $item->loadMissing('labRequest.episode.patient');

        return $item->labRequest->episode->patient;
    }

    public function referenceDate(LabRequestItem $item): CarbonInterface
    {
        $item->loadMissing('labRequest.episode');

        return $item->labRequest->episode->started_at ?? $item->labRequest->requested_at ?? now();
    }

    /**
     * Une analyse demandée, prête pour l'écran.
     *
     * @return array<string, mixed>
     */
    public function present(LabRequestItem $item): array
    {
        $item->loadMissing(['results', 'antibiograms.results', 'startedBy:id,name', 'resultedBy:id,name', 'validatedBy:id,name', 'returnedBy:id,name']);
        $patient = $this->patient($item);
        $date = $this->referenceDate($item);
        $definitions = $this->definitions($item);
        $results = $item->results->keyBy('analysis_catalog_id');
        $anteriority = $this->anteriority($item, $patient, $definitions->pluck('analysis.id')->all());
        $status = $item->currentStatus();

        $nodes = $definitions->map(function (array $row) use ($patient, $date, $results, $anteriority, $item): array {
            /** @var AnalysisCatalog $analysis */
            $analysis = $row['analysis'];
            $mode = LabEntryMode::for($analysis);
            $result = $results->get($analysis->id);
            $reference = $result?->reference_snapshot !== null
                ? ['value' => $result->reference_snapshot, 'profile' => null]
                : $this->references->resolve($analysis, $patient, $date);
            $range = $mode === LabEntryMode::Numeric ? LabReferenceRange::parse($reference['value']) : null;

            return [
                'uuid' => $analysis->uuid,
                'code' => $analysis->code,
                'designation' => $result?->designation_snapshot ?? $analysis->designation,
                'depth' => $row['depth'],
                'is_group' => $analysis->level === AnalysisCatalog::CONTAINER_LEVEL,
                'is_bold' => (bool) $analysis->is_bold,
                'entry_mode' => $mode->value,
                'entry_mode_label' => $mode->label(),
                'takes_result' => $mode->takesResult() && $analysis->level !== AnalysisCatalog::CONTAINER_LEVEL,
                'interpretable' => $mode->interpretable(),
                'unit' => $result?->unit_snapshot ?? $analysis->unit,
                'reference' => $reference['value'],
                'reference_profile' => $reference['profile'],
                'range' => $range?->toArray(),
                'choices' => array_values($analysis->predefined_values ?? []),
                'result' => $result ? $this->presentResult($result) : null,
                'antibiograms' => $mode === LabEntryMode::Culture
                    ? $item->antibiograms->where('analysis_catalog_id', $analysis->id)->map(fn (LabAntibiogram $antibiogram) => $this->presentAntibiogram($antibiogram))->values()->all()
                    : [],
                'anteriority' => $anteriority[$analysis->id] ?? null,
            ];
        })->values();

        return [
            'uuid' => $item->uuid,
            'name' => $item->catalog_item_name_snapshot,
            'code' => $item->catalog_item_code_snapshot,
            'status' => $status->value,
            'status_label' => $status->label(),
            'editable' => $status->editable(),
            'has_definitions' => $nodes->isNotEmpty(),
            'nodes' => $nodes->all(),
            'conclusion' => $item->conclusion,
            'result_value' => $item->result_value,
            'result_notes' => $item->result_notes,
            'started_at' => $item->started_at,
            'started_by' => $item->startedBy?->name,
            'resulted_at' => $item->resulted_at,
            'resulted_by' => $item->resultedBy?->name,
            'validated_at' => $item->validated_at,
            'validated_by' => $item->validatedBy?->name,
            'returned_at' => $item->returned_at,
            'returned_by' => $item->returnedBy?->name,
            'return_reason' => $item->return_reason,
            'critical_count' => $item->results->where('is_critical', true)->count(),
            'pathological_count' => $item->results->where('interpretation', 'PATHOLOGICAL')->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentResult(LabResult $result): array
    {
        return [
            'uuid' => $result->uuid,
            'value' => $result->value,
            'selections' => $result->selections ?? [],
            'interpretation' => $result->interpretation,
            'range_flag' => $result->range_flag,
            'is_critical' => $result->is_critical,
            'updated_at' => $result->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    public function presentAntibiogram(LabAntibiogram $antibiogram): array
    {
        $antibiogram->loadMissing(['results', 'bacterium.family']);

        return [
            'uuid' => $antibiogram->uuid,
            'bacterium' => $antibiogram->bacterium_name_snapshot,
            'bacterium_uuid' => $antibiogram->bacterium?->uuid,
            'family' => $antibiogram->bacterium?->family?->name,
            'family_uuid' => $antibiogram->bacterium?->family?->uuid,
            'notes' => $antibiogram->notes,
            'lines' => $antibiogram->results->map(fn ($line) => [
                'antibiotic_uuid' => $line->antibiotic?->uuid,
                'antibiotic' => $line->antibiotic_name_snapshot,
                'interpretation' => $line->interpretation,
                'measure' => $line->measure !== null ? (float) $line->measure : null,
                'measure_unit' => $line->measure_unit,
            ])->values()->all(),
        ];
    }

    /**
     * Le référentiel de microbiologie actif, pour choisir les germes d'une
     * culture et remplir leurs antibiogrammes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function microbiology(): array
    {
        return LabBacteriumFamily::query()
            ->where('is_active', true)
            ->with([
                'bacteria' => fn ($query) => $query->where('is_active', true),
                'antibiotics' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (LabBacteriumFamily $family) => [
                'uuid' => $family->uuid,
                'name' => $family->name,
                'bacteria' => $family->bacteria->map(fn ($bacterium) => ['uuid' => $bacterium->uuid, 'name' => $bacterium->name])->values()->all(),
                'antibiotics' => $family->antibiotics->map(fn ($antibiotic) => ['uuid' => $antibiotic->uuid, 'name' => $antibiotic->name, 'comment' => $antibiotic->comment])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Le dernier résultat rendu de ce patient pour chaque analyse, sur une
     * autre demande : la paillasse compare, rien n'est recopié.
     *
     * @param  array<int, int>  $analysisIds
     * @return array<int, array<string, mixed>>
     */
    public function anteriority(LabRequestItem $item, Patient $patient, array $analysisIds): array
    {
        if ($analysisIds === []) {
            return [];
        }

        return LabResult::query()
            ->select('lab_results.*', 'lab_request_items.resulted_at as item_resulted_at', 'lab_request_items.status as item_status')
            ->join('lab_request_items', 'lab_request_items.id', '=', 'lab_results.lab_request_item_id')
            ->join('lab_requests', 'lab_requests.id', '=', 'lab_request_items.lab_request_id')
            ->join('episodes', 'episodes.id', '=', 'lab_requests.episode_id')
            ->where('episodes.patient_id', $patient->getKey())
            ->where('lab_request_items.id', '!=', $item->getKey())
            ->whereNotNull('lab_request_items.resulted_at')
            ->whereNull('lab_requests.cancelled_at')
            ->whereIn('lab_results.analysis_catalog_id', $analysisIds)
            ->orderByDesc('lab_request_items.resulted_at')
            ->get()
            ->unique('analysis_catalog_id')
            ->mapWithKeys(fn (LabResult $result) => [$result->analysis_catalog_id => [
                'value' => $result->value,
                'selections' => $result->selections ?? [],
                'unit' => $result->unit_snapshot,
                'interpretation' => $result->interpretation,
                'range_flag' => $result->range_flag,
                'resulted_at' => $result->getAttribute('item_resulted_at'),
                'validated' => $result->getAttribute('item_status') === 'VALIDATED',
            ]])
            ->all();
    }
}
