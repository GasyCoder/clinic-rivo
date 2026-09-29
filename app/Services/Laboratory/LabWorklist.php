<?php

namespace App\Services\Laboratory;

use App\Enums\EpisodePriority;
use App\Enums\LabEntryMode;
use App\Enums\LabItemStatus;
use App\Models\AnalysisCatalog;
use App\Models\LabRequestItem;
use Illuminate\Support\Collection;

/**
 * ADR-214 — la feuille de paillasse : ce qui reste à analyser ici, rangé par
 * discipline (une feuille par paillasse, comme au laboratoire de la clinique),
 * puis par demande, urgences d'abord. On l'imprime pour travailler au poste ; la
 * saisie se fait ensuite à l'écran.
 *
 * Seules les demandes reçues y figurent : avant la réception, rien n'est prélevé.
 * Une analyse confiée à un laboratoire extérieur n'y figure pas : elle se suit
 * sur le bon d'envoi.
 */
class LabWorklist
{
    private const OPEN = [LabItemStatus::Pending, LabItemStatus::InProgress, LabItemStatus::ToRedo];

    public function __construct(private readonly LabDisciplines $disciplines) {}

    /** @return array{sheets: array<int, array<string, mixed>>, disciplines: array<int, array{name: string, count: int}>} */
    public function build(?string $discipline = null): array
    {
        $items = LabRequestItem::query()
            ->whereIn('status', array_map(fn (LabItemStatus $status) => $status->value, self::OPEN))
            ->whereNull('sent_out_at')
            ->whereHas('labRequest', fn ($request) => $request->whereNull('cancelled_at')->whereNotNull('received_at'))
            ->with([
                'labRequest:id,uuid,lab_number,episode_id,notes,received_at,requested_at',
                'labRequest.episode:id,episode_number,patient_id,priority',
                'labRequest.episode.patient:id,uuid,patient_number,first_name,last_name,birth_date,declared_age,sex',
                'labRequest.samples' => fn ($samples) => $samples->whereNull('rejected_at'),
            ])
            ->get();

        // ADR-220 — une demande dont un résultat est déjà envoyé au médecin (reprise)
        // ne part pas à la corbeille : la sélection le sait avant le clic.
        $sentRequestIds = LabRequestItem::query()
            ->whereIn('lab_request_id', $items->pluck('lab_request_id')->unique())
            ->whereNotNull('sent_at')
            ->distinct()
            ->pluck('lab_request_id')
            ->flip();

        $byItem = $this->disciplines->forCatalogItems($items->pluck('catalog_item_id')->all());
        $codes = $this->analysisCodes($items->pluck('catalog_item_id')->unique()->all());

        $grouped = $items->groupBy(fn (LabRequestItem $item) => $byItem[$item->catalog_item_id] ?? LabDisciplines::NONE);

        $counts = $grouped->map(fn (Collection $group, string $name) => ['name' => $name, 'count' => $group->count()])
            ->sortBy(fn ($row) => [$row['name'] === LabDisciplines::NONE ? 1 : 0, $row['name']])
            ->values()->all();

        $sheets = $grouped
            ->when($discipline !== null && $discipline !== '', fn ($groups) => $groups->only([$discipline]))
            ->map(fn (Collection $group, string $name) => [
                'discipline' => $name,
                'count' => $group->count(),
                'rows' => $group->groupBy('lab_request_id')
                    ->map(function (Collection $requestItems) use ($codes, $sentRequestIds): array {
                        $request = $requestItems->first()->labRequest;

                        return [
                            'request_uuid' => $request->uuid,
                            'trashable' => ! $sentRequestIds->has($request->getKey()),
                            'lab_number' => $request->lab_number,
                            'emergency' => $request->episode?->priority === EpisodePriority::Emergency,
                            'received_at' => $request->received_at,
                            'notes' => $request->notes,
                            'episode_number' => $request->episode?->episode_number,
                            'patient' => LabRequestPresenter::patient($request->episode->patient),
                            'samples' => $request->samples->map(fn ($sample) => [
                                'barcode' => $sample->barcode,
                                'tube' => $sample->tube_code_snapshot,
                                'hex' => $sample->tube_color_hex_snapshot,
                            ])->values()->all(),
                            'items' => $requestItems->map(fn (LabRequestItem $item) => [
                                'uuid' => $item->uuid,
                                'name' => $item->catalog_item_name_snapshot,
                                'status' => $item->currentStatus()->value,
                                'status_label' => $item->currentStatus()->label(),
                                'analyses' => $codes[$item->catalog_item_id] ?? [],
                            ])->values()->all(),
                        ];
                    })
                    ->sortBy(fn (array $row) => [$row['emergency'] ? 0 : 1, (string) $row['received_at']])
                    ->values()->all(),
            ])
            ->sortBy(fn ($sheet) => [$sheet['discipline'] === LabDisciplines::NONE ? 1 : 0, $sheet['discipline']])
            ->values()->all();

        return ['sheets' => $sheets, 'disciplines' => $counts];
    }

    /**
     * Les analyses qui prennent un résultat, par prestation — ce que le poste
     * aura à remplir.
     *
     * @param  array<int, int>  $catalogItemIds
     * @return array<int, array<int, string>>
     */
    private function analysisCodes(array $catalogItemIds): array
    {
        if ($catalogItemIds === []) {
            return [];
        }

        return AnalysisCatalog::query()
            ->whereIn('catalog_item_id', $catalogItemIds)
            ->where('is_active', true)
            ->where('level', '!=', AnalysisCatalog::CONTAINER_LEVEL)
            ->orderBy('display_order')
            ->orderBy('designation')
            ->get(['catalog_item_id', 'code', 'designation', 'entry_mode', 'result_type', 'source_metadata', 'predefined_values'])
            ->filter(fn (AnalysisCatalog $analysis) => LabEntryMode::for($analysis)->takesResult())
            ->groupBy('catalog_item_id')
            ->map(fn ($rows) => $rows->map(fn (AnalysisCatalog $analysis) => $analysis->code ?: $analysis->designation)->take(30)->values()->all())
            ->all();
    }
}
