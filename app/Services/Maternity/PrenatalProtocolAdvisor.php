<?php

namespace App\Services\Maternity;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use App\Models\Pregnancy;
use App\Models\User;
use App\Support\Maternity\PrenatalFollowUpReference as Reference;
use Carbon\CarbonImmutable;

/**
 * ADR-204 — ce qu'il est habituel de demander à ce terme, confronté à ce qui a
 * déjà été demandé pour cette grossesse.
 *
 * Il ne renvoie que des **suggestions** : aucun examen n'est demandé, aucun
 * diagnostic posé, aucune consultation retenue. « Demander » reste un geste de
 * la sage-femme, par le chemin ordinaire des demandes (ADR-105, ADR-106).
 *
 * Le statut d'une suggestion se lit sur les demandes réelles de la grossesse
 * faites **pendant sa période** : une NFS du premier trimestre ne coche pas
 * celle du sixième mois. Sans le droit de voir une famille d'examens, son
 * statut n'est pas deviné : il est dit « non visible ».
 */
final class PrenatalProtocolAdvisor
{
    public function __construct(
        private readonly PregnancyDatingService $dating,
        private readonly PregnancyParaclinicalHistory $history,
    ) {}

    /**
     * @param  array{groups: list<array<string, mixed>>, restricted: array{lab: bool, imaging: bool}}|null  $history
     * @return array<string, mixed>
     */
    public function advise(Pregnancy $pregnancy, User $viewer, mixed $at = null, ?array $history = null): array
    {
        $age = $this->dating->gestationalAge($pregnancy, $at ?? now());
        $weeks = $age['weeks'] ?? null;
        $period = Reference::periodFor($weeks);
        $history ??= $this->history->forPregnancy($pregnancy, $viewer);
        $entries = collect($history['groups'])->flatMap(fn (array $group) => $group['entries']);
        $restricted = $history['restricted'];

        $base = [
            'validated' => Reference::VALIDATED,
            'notice' => Reference::NOTICE,
            'gestational_age_label' => $age['label'] ?? null,
            'current_period' => $period === null ? null : [
                'key' => $period['key'],
                'label' => $period['label'],
                'range_label' => "{$period['from']} à {$period['to']} SA + 6 j",
            ],
            'previously_done' => $entries->where('status', 'DONE')->map(fn (array $entry) => [
                'kind' => $entry['kind'],
                'exam' => $entry['exam'],
                'resulted_at' => $entry['resulted_at'],
            ])->values()->all(),
            'pending_requests' => $entries->where('status', 'REQUESTED')->map(fn (array $entry) => [
                'kind' => $entry['kind'],
                'exam' => $entry['exam'],
                'requested_at' => $entry['requested_at'],
            ])->values()->all(),
            'suggestions' => [],
        ];

        if ($period === null) {
            return $base;
        }

        $catalog = CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->whereIn('module', [CatalogModule::Laboratory->value, CatalogModule::Imaging->value])
            ->whereIn('code', collect($period['items'])->pluck('code')->all())
            ->get(['uuid', 'code', 'name'])
            ->keyBy('code');

        $base['suggestions'] = collect($period['items'])->map(function (array $item) use ($catalog, $entries, $restricted, $period, $pregnancy, $weeks): array {
            $kind = $item['category'] === 'LAB' ? 'lab' : 'imaging';
            $inPeriod = $entries
                ->where('code', $item['code'])
                ->where('status', '!=', 'CANCELLED')
                ->filter(fn (array $entry) => $this->inPeriod($pregnancy, $entry['requested_at'], $period));

            $status = match (true) {
                $restricted[$kind] => 'UNKNOWN',
                $inPeriod->contains('status', 'DONE') => 'DONE',
                $inPeriod->contains('status', 'REQUESTED') => 'REQUESTED',
                default => 'NOT_REQUESTED',
            };

            return [
                'category' => $item['category'],
                'code' => $item['code'],
                'label' => $catalog->get($item['code'])?->name ?? $item['label'],
                'window_label' => Reference::windowLabel($item['from'], $item['to']),
                // Avant, dans ou après sa fenêtre habituelle — dit, jamais imposé.
                'timing' => match (true) {
                    $item['from'] === null || $weeks === null => 'DUE',
                    $weeks < $item['from'] => 'UPCOMING',
                    $weeks > $item['to'] => 'PAST',
                    default => 'DUE',
                },
                'status' => $status,
                // Un examen absent du catalogue du site se montre sans « Demander ».
                'catalog_item_uuid' => $catalog->get($item['code'])?->uuid,
            ];
        })->values()->all();

        return $base;
    }

    /** @param array{from: int, to: int} $period */
    private function inPeriod(Pregnancy $pregnancy, mixed $requestedAt, array $period): bool
    {
        if ($requestedAt === null) {
            return false;
        }

        $age = $this->dating->gestationalAge($pregnancy, CarbonImmutable::parse($requestedAt));

        // Sans datation, la période ne se lit pas : toute demande de la grossesse compte.
        return $age === null || ($age['weeks'] >= $period['from'] && $age['weeks'] <= $period['to']);
    }
}
