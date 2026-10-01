<?php

namespace App\Services\Laboratory;

use App\Enums\LabEntryMode;
use App\Models\AnalysisCatalog;
use App\Models\LabAntibiogram;
use App\Models\LabBacteriumFamily;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\User;
use App\Support\Laboratory\LabCriticalRange;
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
        $item->loadMissing(['results', 'notes.writtenBy:id,name', 'antibiograms.results', 'startedBy:id,name', 'resultedBy:id,name', 'validatedBy:id,name', 'approvedBy:id,name', 'returnedBy:id,name', 'sentOutBy:id,name']);
        $patient = $this->patient($item);
        $date = $this->referenceDate($item);
        $definitions = $this->definitions($item);
        $results = $item->results->keyBy('analysis_catalog_id');
        $notes = $item->notes->keyBy('analysis_catalog_id');
        $anteriority = $this->anteriority($item, $patient, $definitions->pluck('analysis.id')->all());
        $status = $item->currentStatus();

        $nodes = $definitions->map(function (array $row) use ($patient, $date, $results, $notes, $anteriority, $item): array {
            /** @var AnalysisCatalog $analysis */
            $analysis = $row['analysis'];
            $mode = LabEntryMode::for($analysis);
            $result = $results->get($analysis->id);
            $reference = $result?->reference_snapshot !== null
                ? ['value' => $result->reference_snapshot, 'profile' => null]
                : $this->references->resolve($analysis, $patient, $date);
            $range = $mode === LabEntryMode::Numeric ? LabReferenceRange::parse($reference['value']) : null;
            $critical = $mode === LabEntryMode::Numeric ? LabCriticalRange::resolve($analysis, $patient, $date) : null;

            return [
                'uuid' => $analysis->uuid,
                'code' => $analysis->code,
                'designation' => $result?->designation_snapshot ?? $analysis->designation,
                'depth' => $row['depth'],
                'is_group' => $analysis->level === AnalysisCatalog::CONTAINER_LEVEL,
                // Un simple intitulé (« Soit » dans la NFS) sépare deux blocs de lignes :
                // ce n'est ni une saisie ni un groupe, il ne porte pas de conclusion.
                'is_label' => $mode === LabEntryMode::Label && $analysis->level !== AnalysisCatalog::CONTAINER_LEVEL,
                'is_bold' => (bool) $analysis->is_bold,
                'entry_mode' => $mode->value,
                'entry_mode_label' => $mode->label(),
                'takes_result' => $mode->takesResult() && $analysis->level !== AnalysisCatalog::CONTAINER_LEVEL,
                'interpretable' => $mode->interpretable(),
                'unit' => $result?->unit_snapshot ?? $analysis->unit,
                'reference' => $reference['value'],
                'reference_profile' => $reference['profile'],
                'range' => $range?->toArray(),
                'critical' => $critical?->toArray(),
                'choices' => array_values($analysis->predefined_values ?? []),
                'result' => $result ? $this->presentResult($result) : null,
                'antibiograms' => $mode === LabEntryMode::Culture
                    ? $item->antibiograms->where('analysis_catalog_id', $analysis->id)->map(fn (LabAntibiogram $antibiogram) => $this->presentAntibiogram($antibiogram))->values()->all()
                    : [],
                'anteriority' => $anteriority[$analysis->id] ?? null,
                // ADR-218 — la conclusion partielle de la ligne.
                'note' => $notes->get($analysis->id)?->note,
                'note_by' => $notes->get($analysis->id)?->writtenBy?->name,
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
            // ADR-219 — plus de conclusion par analyse ; une conclusion saisie avant reste lisible.
            'legacy_conclusion' => $item->conclusion,
            // Une analyse déjà envoyée au médecin ne se remet jamais à zéro.
            'resettable' => $status->editable() && $item->sent_at === null,
            'result_value' => $item->result_value,
            'result_notes' => $item->result_notes,
            'started_at' => $item->started_at,
            'started_by' => $item->startedBy?->name,
            'resulted_at' => $item->resulted_at,
            'resulted_by' => $item->resultedBy?->name,
            'validated_at' => $item->validated_at,
            'validated_by' => $item->validatedBy?->name,
            // Amendement ADR-216 quater — la validation du médecin : « à valider » ou « validé ».
            'approval' => $this->approval($item),
            'returned_at' => $item->returned_at,
            'returned_by' => $item->returnedBy?->name,
            'return_reason' => $item->return_reason,
            'sent_out' => $item->sent_out_at ? [
                'laboratory' => $item->external_lab_name,
                'reference' => $item->external_reference,
                'notes' => $item->sent_out_notes,
                'at' => $item->sent_out_at,
                'by' => $item->sentOutBy?->name,
            ] : null,
            'critical_count' => $item->results->where('is_critical', true)->count(),
            'pathological_count' => $item->results->where('interpretation', 'PATHOLOGICAL')->count(),
        ];
    }

    /**
     * Amendement ADR-216 quater — où en est la validation du médecin, en un mot.
     *
     * @return array{state: string, label: string, at: mixed, by: ?string}|null null tant que rien n'est envoyé
     */
    public function approval(LabRequestItem $item): ?array
    {
        if (! $item->isDelivered()) {
            return null;
        }

        return match (true) {
            $item->isApproved() => ['state' => 'APPROVED', 'label' => 'Validée par le médecin', 'at' => $item->approved_at, 'by' => $item->approvedBy?->name],
            $item->awaitsApproval() => ['state' => 'AWAITING', 'label' => 'Terminée · à valider par le médecin', 'at' => null, 'by' => null],
            default => ['state' => 'IN_CORRECTION', 'label' => 'Reprise par le laboratoire', 'at' => null, 'by' => null],
        };
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
            'critical_source' => $result->critical_source,
            'critical_snapshot' => $result->critical_snapshot,
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
    public function anteriority(LabRequestItem $item, Patient $patient, array $analysisIds, ?User $viewer = null, bool $approvedOnly = false): array
    {
        if ($analysisIds === []) {
            return [];
        }

        $previous = LabResult::query()
            ->select('lab_results.*', 'lab_request_items.resulted_at as item_resulted_at', 'lab_request_items.status as item_status', 'lab_request_items.lab_request_id as item_request_id')
            ->join('lab_request_items', 'lab_request_items.id', '=', 'lab_results.lab_request_item_id')
            ->join('lab_requests', 'lab_requests.id', '=', 'lab_request_items.lab_request_id')
            ->join('episodes', 'episodes.id', '=', 'lab_requests.episode_id')
            ->where('episodes.patient_id', $patient->getKey())
            ->where('lab_request_items.id', '!=', $item->getKey())
            ->whereNotNull('lab_request_items.resulted_at')
            ->whereNull('lab_requests.cancelled_at')
            // ADR-216 / ADR-218 — hors du laboratoire, seule une valeur envoyée sert d'antériorité.
            ->when($viewer !== null, fn ($query) => $query->whereNotNull('lab_request_items.sent_at'))
            // Amendement ADR-216 quater — la Réception ne lit que des valeurs validées par le médecin.
            ->when($approvedOnly, fn ($query) => $query->whereNotNull('lab_request_items.approved_at')
                ->whereNotNull('lab_request_items.sent_at')->where('lab_request_items.status', 'VALIDATED'))
            ->whereIn('lab_results.analysis_catalog_id', $analysisIds)
            ->orderByDesc('lab_request_items.resulted_at')
            ->get();

        if ($viewer !== null && $previous->isNotEmpty()) {
            // … et jamais celle d'une demande adressée à un confrère qu'il n'a pas ouverte.
            $access = app(LabResultAccess::class);
            $sealed = LabRequest::query()->whereIn('id', $previous->pluck('item_request_id')->unique())->get()
                ->filter(fn (LabRequest $request) => $access->sealed($request, $viewer))
                ->pluck('id')->all();
            $previous = $previous->reject(fn (LabResult $result) => in_array((int) $result->getAttribute('item_request_id'), $sealed, true));
        }

        return $previous
            ->unique('analysis_catalog_id')
            ->mapWithKeys(fn (LabResult $result) => [$result->analysis_catalog_id => [
                'value' => $result->value,
                'selections' => $result->selections ?? [],
                'unit' => $result->unit_snapshot,
                'entry_mode' => $result->entry_mode,
                'interpretation' => $result->interpretation,
                'range_flag' => $result->range_flag,
                'resulted_at' => $result->getAttribute('item_resulted_at'),
                'validated' => $result->getAttribute('item_status') === 'VALIDATED',
            ]])
            ->all();
    }
}
