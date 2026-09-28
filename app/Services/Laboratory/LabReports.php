<?php

namespace App\Services\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * ADR-214 — les rapports du Laboratoire (CDC §14 : `laboratory.reports.view`,
 * `laboratory.reports.export`) : l'activité d'une période, les délais, la
 * répartition par discipline et par origine. Aucun montant : le Laboratoire
 * n'encaisse rien et ne compte pas l'argent (ADR-014).
 *
 * Chaque chiffre se lit sur une date réellement enregistrée — demandée, reçue,
 * rendue, validée — jamais extrapolé. Une demande retirée par le prescripteur
 * n'y est pas comptée.
 */
class LabReports
{
    public const MAX_DAYS = 366;

    public function __construct(private readonly LabDisciplines $disciplines) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function period(?string $from, ?string $to): array
    {
        $parse = fn (?string $date) => $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? CarbonImmutable::parse($date) : null;
        $end = ($parse($to) ?? CarbonImmutable::today())->endOfDay();
        $start = ($parse($from) ?? $end->subDays(29))->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS)->startOfDay();
        }

        return [$start, $end];
    }

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $between = [$start, $end];
        $live = fn ($query) => $query->whereNull('cancelled_at');
        $items = fn () => LabRequestItem::query()->whereHas('labRequest', $live);

        $resulted = $items()->whereBetween('resulted_at', $between)
            ->with('labRequest:id,requested_at,received_at')
            ->get(['id', 'lab_request_id', 'catalog_item_id', 'catalog_item_name_snapshot', 'resulted_at', 'validated_at', 'sent_out_at']);
        $validated = $items()->whereBetween('validated_at', $between)->get(['id', 'resulted_at', 'validated_at']);
        $resultedIds = $resulted->pluck('id');

        $requests = LabRequest::query()->whereNull('cancelled_at')->whereBetween('requested_at', $between)
            ->withCount('items')
            ->get(['id', 'maternity_record_id', 'hospital_stay_id', 'consultation_id', 'requested_at']);
        $requestedItems = $items()->whereHas('labRequest', fn ($request) => $request->whereBetween('requested_at', $between))
            ->get(['id', 'catalog_item_id', 'catalog_item_name_snapshot']);

        $byItem = $this->disciplines->forCatalogItems($resulted->pluck('catalog_item_id')->merge($requestedItems->pluck('catalog_item_id'))->all());

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => (int) $start->diffInDays($end) + 1],
            'totals' => [
                'requests' => $requests->count(),
                'requested_items' => $requestedItems->count(),
                'received' => LabRequest::query()->whereNull('cancelled_at')->whereBetween('received_at', $between)->count(),
                'resulted' => $resulted->count(),
                'validated' => $validated->count(),
                'returned' => $items()->whereBetween('returned_at', $between)->count(),
                'sent_out' => $items()->whereBetween('sent_out_at', $between)->count(),
                'critical' => LabResult::query()->whereIn('lab_request_item_id', $resultedIds)->where('is_critical', true)->count(),
                'pathological' => LabResult::query()->whereIn('lab_request_item_id', $resultedIds)->where('interpretation', 'PATHOLOGICAL')->count(),
                // L'arriéré se lit maintenant, pas sur la période : ce qui attend encore.
                'backlog_to_receive' => LabRequest::query()->whereNull('cancelled_at')->whereNull('received_at')->whereHas('items')->count(),
                'backlog_open' => $items()->whereHas('labRequest', fn ($request) => $request->whereNotNull('received_at'))
                    ->whereIn('status', [LabItemStatus::Pending->value, LabItemStatus::InProgress->value, LabItemStatus::ToRedo->value])->count(),
                'backlog_to_validate' => $items()->where('status', LabItemStatus::Completed->value)->count(),
            ],
            'delays' => [
                'request_to_result' => $this->delay($resulted->map(fn ($item) => [$item->labRequest?->requested_at, $item->resulted_at])),
                'reception_to_result' => $this->delay($resulted->map(fn ($item) => [$item->labRequest?->received_at, $item->resulted_at])),
                'result_to_validation' => $this->delay($validated->map(fn ($item) => [$item->resulted_at, $item->validated_at])),
            ],
            'disciplines' => $resulted->groupBy(fn ($item) => $byItem[$item->catalog_item_id] ?? LabDisciplines::NONE)
                ->map(fn (Collection $group, string $name) => ['key' => $name, 'label' => $name, 'value' => $group->count()])
                ->sortByDesc('value')->values()->all(),
            'top_analyses' => $requestedItems->groupBy('catalog_item_name_snapshot')
                ->map(fn (Collection $group, string $name) => ['key' => $name, 'label' => $name, 'value' => $group->count()])
                ->sortByDesc('value')->take(10)->values()->all(),
            'origins' => $requests->groupBy(fn (LabRequest $request) => ParaclinicalRequestPresenter::origin($request))
                ->map(fn (Collection $group, string $name) => ['key' => $name, 'label' => $name, 'value' => $group->count(), 'hint' => $group->sum('items_count').' analyse(s)'])
                ->sortByDesc('value')->values()->all(),
            'trend' => $this->trend($start, $end, $requestedItems, $resulted, $validated),
        ];
    }

    /**
     * Une ligne par analyse demandée sur la période — ce que l'export Excel porte.
     *
     * @return iterable<int, array<int, mixed>>
     */
    public function exportRows(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        $items = LabRequestItem::query()
            ->whereHas('labRequest', fn ($request) => $request->whereNull('cancelled_at')->whereBetween('requested_at', [$start, $end]))
            ->with([
                'labRequest:id,uuid,lab_number,episode_id,maternity_record_id,hospital_stay_id,consultation_id,requested_at,received_at',
                'labRequest.episode:id,episode_number,patient_id,priority',
                'labRequest.episode.patient:id,patient_number,first_name,last_name',
                'results:id,lab_request_item_id,is_critical,interpretation',
            ])
            ->orderBy('id')
            ->get();
        $byItem = $this->disciplines->forCatalogItems($items->pluck('catalog_item_id')->all());
        $format = fn ($date) => $date?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '';

        foreach ($items as $item) {
            $request = $item->labRequest;
            $patient = $request->episode?->patient;
            $hours = $request->requested_at && $item->resulted_at ? round($request->requested_at->diffInMinutes($item->resulted_at) / 60, 1) : null;

            yield [
                $format($request->requested_at),
                $request->lab_number ?? '',
                $patient?->patient_number ?? '',
                trim(($patient?->last_name ?? '').' '.($patient?->first_name ?? '')),
                $request->episode?->episode_number ?? '',
                ParaclinicalRequestPresenter::origin($request),
                $item->catalog_item_name_snapshot,
                $byItem[$item->catalog_item_id] ?? LabDisciplines::NONE,
                $item->currentStatus()->label(),
                $format($request->received_at),
                $format($item->resulted_at),
                $format($item->validated_at),
                $hours,
                $item->results->where('is_critical', true)->count(),
                $item->results->where('interpretation', 'PATHOLOGICAL')->count(),
                $item->external_lab_name ?? '',
                $request->episode?->priority?->value === 'EMERGENCY' ? 'Oui' : 'Non',
            ];
        }
    }

    public const EXPORT_HEADERS = [
        'Demandée le', 'N° laboratoire', 'N° patient', 'Patient', 'Passage', 'Origine', 'Analyse', 'Discipline',
        'État', 'Reçue le', 'Rendue le', 'Validée le', 'Délai demande → résultat (h)', 'Résultats critiques',
        'Résultats pathologiques', 'Laboratoire extérieur', 'Urgence',
    ];

    /**
     * Médiane et moyenne en heures ; `null` quand rien n'a été mesuré — jamais
     * zéro, qui se lirait « instantané » (ADR-102).
     *
     * @param  Collection<int, array{0: mixed, 1: mixed}>  $pairs
     * @return array{count: int, median_hours: ?float, average_hours: ?float}
     */
    private function delay(Collection $pairs): array
    {
        $minutes = $pairs
            ->filter(fn (array $pair) => $pair[0] !== null && $pair[1] !== null && $pair[1] >= $pair[0])
            ->map(fn (array $pair) => $pair[0]->diffInMinutes($pair[1]))
            ->sort()
            ->values();

        if ($minutes->isEmpty()) {
            return ['count' => 0, 'median_hours' => null, 'average_hours' => null];
        }

        $count = $minutes->count();
        $median = $count % 2 ? $minutes[intdiv($count, 2)] : ($minutes[$count / 2 - 1] + $minutes[$count / 2]) / 2;

        return ['count' => $count, 'median_hours' => round($median / 60, 1), 'average_hours' => round($minutes->avg() / 60, 1)];
    }

    /** @return array{dates: array<int, string>, series: array<int, array<string, mixed>>} */
    private function trend(CarbonImmutable $start, CarbonImmutable $end, Collection $requested, Collection $resulted, Collection $validated): array
    {
        $dates = collect(CarbonPeriod::create($start->startOfDay(), '1 day', $end->startOfDay()))
            ->map(fn ($date) => $date->toDateString())->values();

        $requestDates = LabRequestItem::query()->whereIn('lab_request_items.id', $requested->pluck('id'))
            ->join('lab_requests', 'lab_requests.id', '=', 'lab_request_items.lab_request_id')
            ->pluck('lab_requests.requested_at')
            ->map(fn ($date) => CarbonImmutable::parse($date)->timezone(config('app.timezone'))->toDateString());

        $series = fn (string $key, string $label, string $tone, Collection $days) => [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'values' => $dates->map(fn (string $date) => $days->filter(fn ($day) => $day === $date)->count())->all(),
            'total' => $days->count(),
        ];
        $day = fn ($date) => $date?->timezone(config('app.timezone'))->toDateString();

        return [
            'dates' => $dates->all(),
            'series' => [
                $series('requested', 'Analyses demandées', 'navy', $requestDates),
                $series('resulted', 'Rendues', 'cyan', $resulted->map(fn ($item) => $day($item->resulted_at))),
                $series('validated', 'Validées', 'green', $validated->map(fn ($item) => $day($item->validated_at))),
            ],
        ];
    }
}
