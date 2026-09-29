<?php

namespace App\Services\Maternity;

use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\MaternityRecord;
use App\Models\Pregnancy;
use App\Models\User;
use App\Services\Laboratory\LabResultAccess;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;
use Illuminate\Support\Collection;

/**
 * ADR-204 — le suivi paraclinique d'une grossesse, relu, jamais recopié.
 *
 * Les examens restent ceux du Laboratoire (`LabRequest`) et de l'imagerie
 * (`ImagingRequest`) : leur résultat est lu sur la demande, là où le service
 * qui l'a produit l'a écrit. Ce presenter les regroupe par consultation de la
 * grossesse — celles demandées depuis la Maternité comme celles demandées au
 * même passage par la Réception ou la Médecine — pour qu'on retrouve, le jour
 * de l'accouchement, tout ce qui a été fait depuis le premier trimestre.
 *
 * Chaque famille reste gardée par son droit (`laboratory_orders.view`,
 * `imaging_orders.view`) : sans lui, elle n'est pas servie — jamais servie
 * vide, qui se lirait « aucun examen » (ADR-054, ADR-102).
 */
final class PregnancyParaclinicalHistory
{
    public function __construct(
        private readonly PregnancyDatingService $dating,
        private readonly LabResultAccess $labAccess,
    ) {}

    /**
     * @return array{groups: list<array<string, mixed>>, restricted: array{lab: bool, imaging: bool}, counts: array{lab: int, imaging: int, pending: int}}
     */
    public function forPregnancy(Pregnancy $pregnancy, User $viewer, ?MaternityRecord $current = null): array
    {
        $canLab = $viewer->can('laboratory_orders.view');
        $canImaging = $viewer->can('imaging_orders.view');
        $pregnancy->loadMissing('maternityRecords.episode');
        $records = $pregnancy->maternityRecords
            ->sortBy(fn (MaternityRecord $record) => $record->episode?->started_at?->getTimestamp() ?? $record->created_at?->getTimestamp() ?? 0)
            ->values();
        $entries = $this->entries($records->pluck('episode_id')->filter()->all(), $canLab, $canImaging, $viewer);

        $groups = $records->map(function (MaternityRecord $record) use ($entries, $current, $pregnancy): array {
            $at = $record->episode?->started_at ?? $record->created_at;
            $weeks = $record->gestational_age_weeks;
            $age = $weeks !== null
                ? $this->dating->label($weeks, $record->gestational_age_days ?? 0)
                : ($this->dating->gestationalAge($pregnancy, $at)['label'] ?? null);

            return [
                'record_uuid' => $record->uuid,
                'label' => $record->encounter_type?->label() ?? $record->effectiveEncounterType()->label(),
                'at' => $at,
                'episode_number' => $record->episode?->episode_number,
                'gestational_age_label' => $age,
                'is_current' => $current?->is($record) ?? false,
                'entries' => $entries->get($record->episode_id, collect())->values()->all(),
            ];
        })->values();

        $flat = $groups->flatMap(fn (array $group) => $group['entries']);

        return [
            'groups' => $groups->all(),
            'restricted' => ['lab' => ! $canLab, 'imaging' => ! $canImaging],
            'counts' => [
                'lab' => $flat->where('kind', 'LAB')->where('status', '!=', 'CANCELLED')->count(),
                'imaging' => $flat->where('kind', 'IMAGING')->where('status', '!=', 'CANCELLED')->count(),
                'pending' => $flat->where('status', 'REQUESTED')->count(),
            ],
        ];
    }

    /**
     * Une ligne par examen demandé, groupée par passage.
     *
     * @param  list<int>  $episodeIds
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function entries(array $episodeIds, bool $canLab, bool $canImaging, User $viewer): Collection
    {
        if ($episodeIds === []) {
            return collect();
        }

        $lab = $canLab
            ? LabRequest::query()->whereIn('episode_id', $episodeIds)->with(['items', 'requestedBy:id,name', 'resultsRecipient:id,name'])->get()
            : collect();
        $imaging = $canImaging
            ? ImagingRequest::query()->whereIn('episode_id', $episodeIds)->with(['items', 'requestedBy:id,name'])->get()
            : collect();

        return $lab->map(fn (LabRequest $request) => [$request, 'LAB'])
            ->concat($imaging->map(fn (ImagingRequest $request) => [$request, 'IMAGING']))
            ->flatMap(fn (array $pair) => $pair[0]->items->map(fn ($item): array => [
                'episode_id' => $pair[0]->episode_id,
                'kind' => $pair[1],
                'request_uuid' => $pair[0]->uuid,
                'item_uuid' => $item->uuid,
                'exam' => $item->catalog_item_name_snapshot,
                'code' => $item->catalog_item_code_snapshot,
                // ADR-216 — une analyse n'est « faite » pour la sage-femme qu'une fois envoyée.
                'status' => $pair[0]->cancelled_at !== null ? 'CANCELLED' : (self::delivered($item) ? 'DONE' : 'REQUESTED'),
                'requested_at' => $pair[0]->requested_at,
                'requested_by' => $pair[0]->requestedBy?->name,
                'resulted_at' => self::delivered($item) ? $item->resulted_at : null,
                'origin' => ParaclinicalRequestPresenter::origin($pair[0]),
                // Le résultat d'une analyse est lu tel que le Laboratoire l'a
                // écrit ; celui de l'imagerie se relit sur son compte rendu.
                'result' => $pair[1] === 'LAB' && self::delivered($item) && ! $this->labAccess->sealed($pair[0], $viewer) ? $item->result_value : null,
                'sealed' => $pair[1] === 'LAB' && self::delivered($item) ? $this->labAccess->sealFor($pair[0], $viewer) : null,
                'results_url' => $pair[1] === 'LAB' && self::delivered($item) ? "/resultats-analyses/{$pair[0]->uuid}" : null,
                'print_url' => $pair[1] === 'IMAGING' && $item->resulted_at !== null
                    ? "/medicine/imaging-requests/{$item->uuid}/compte-rendu"
                    : null,
            ]))
            ->sortBy(fn (array $entry) => $entry['requested_at']?->getTimestamp() ?? 0)
            ->groupBy('episode_id');
    }

    /** Une analyse ne compte que envoyée ; un compte rendu d'imagerie, dès qu'il est écrit. */
    private static function delivered(mixed $item): bool
    {
        return $item instanceof LabRequestItem ? $item->isDelivered() : $item->resulted_at !== null;
    }
}
