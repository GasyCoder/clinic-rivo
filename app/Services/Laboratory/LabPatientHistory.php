<?php

namespace App\Services\Laboratory;

use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\User;
use App\Support\Laboratory\LabEntryOptions;
use Illuminate\Support\Collection;

/**
 * ADR-214 — l'historique des résultats d'un patient : chaque analyse, ses
 * valeurs d'une demande à l'autre (la « trace patient » du laboratoire de la
 * clinique). Seul ce qui a été rendu y figure ; une valeur non validée le dit.
 * Rien n'est recalculé : les valeurs, unités et références sont celles figées à
 * la saisie.
 */
class LabPatientHistory
{
    public const MAX_COLUMNS = 12;

    /** @return array<string, mixed> */
    public function for(Patient $patient, ?User $viewer = null): array
    {
        $items = LabRequestItem::query()
            ->whereNotNull('resulted_at')
            ->whereHas('labRequest', fn ($request) => $request->whereNull('cancelled_at')
                ->whereHas('episode', fn ($episode) => $episode->where('patient_id', $patient->getKey())))
            ->with(['labRequest:id,uuid,lab_number,requested_at,requested_by,results_recipient_id', 'labRequest.recipients:users.id', 'results'])
            ->orderByDesc('resulted_at')
            ->get();

        // ADR-216 — hors du laboratoire, seul ce qui a été envoyé se relit, et
        // un résultat adressé à un confrère s'ouvre d'abord sur sa page.
        $sealedRequests = 0;
        if ($viewer !== null && ! $viewer->can('laboratory_results.create')) {
            $access = app(LabResultAccess::class);
            $items = $items->filter(fn (LabRequestItem $item) => $item->isDelivered());
            $sealed = $items->filter(fn (LabRequestItem $item) => $access->sealed($item->labRequest, $viewer));
            $sealedRequests = $sealed->pluck('lab_request_id')->unique()->count();
            $items = $items->diffKeys($sealed);
        }

        // Une colonne par demande, la plus récente d'abord.
        $columns = $items->groupBy('lab_request_id')
            ->map(fn (Collection $group) => [
                'request_uuid' => $group->first()->labRequest->uuid,
                'lab_number' => $group->first()->labRequest->lab_number,
                'date' => $group->max('resulted_at'),
            ])
            ->sortByDesc('date')
            ->take(self::MAX_COLUMNS)
            ->values();
        $columnIds = $columns->pluck('request_uuid')->flip();

        $rows = [];
        foreach ($items as $item) {
            $column = $item->labRequest->uuid;
            if (! $columnIds->has($column)) {
                continue;
            }
            $validated = $item->validated_at !== null;

            if ($item->results->isEmpty()) {
                $key = "item:{$item->catalog_item_id}";
                $rows[$key] ??= ['key' => $key, 'group' => $item->catalog_item_name_snapshot, 'designation' => $item->catalog_item_name_snapshot, 'unit' => null, 'reference' => null, 'values' => []];
                $rows[$key]['values'][$column] ??= ['text' => $item->result_value, 'flag' => null, 'critical' => false, 'validated' => $validated];

                continue;
            }

            foreach ($item->results as $result) {
                /** @var LabResult $result */
                if ($result->isBlank()) {
                    continue;
                }
                $key = "analysis:{$result->analysis_catalog_id}";
                $rows[$key] ??= [
                    'key' => $key,
                    'group' => $item->catalog_item_name_snapshot,
                    'designation' => $result->designation_snapshot,
                    'unit' => $result->unit_snapshot,
                    'reference' => $result->reference_snapshot,
                    'values' => [],
                ];
                $rows[$key]['values'][$column] ??= [
                    'text' => $this->text($result),
                    'flag' => $result->range_flag,
                    'interpretation' => $result->interpretation,
                    'critical' => $result->is_critical,
                    'validated' => $validated,
                ];
            }
        }

        $groups = collect($rows)->groupBy('group')
            ->map(fn (Collection $group, string $name) => ['name' => $name, 'rows' => $group->values()->all()])
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'columns' => $columns->all(),
            'groups' => $groups,
            'total_requests' => $items->pluck('lab_request_id')->unique()->count(),
            'sealed_requests' => $sealedRequests,
        ];
    }

    private function text(LabResult $result): string
    {
        $selections = $result->selections ?? [];
        $detail = is_array($selections) && isset($selections['detail']) ? " ({$selections['detail']})" : '';

        return match ($result->entry_mode) {
            'NUGENT' => $result->value !== null ? "Score {$result->value}/10" : 'Score incomplet',
            'CULTURE' => LabEntryOptions::CULTURE[$result->value] ?? (string) $result->value,
            default => trim((string) $result->value).$detail,
        };
    }
}
