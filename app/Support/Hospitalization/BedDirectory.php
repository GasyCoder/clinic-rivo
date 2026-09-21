<?php

namespace App\Support\Hospitalization;

use App\Enums\HospitalBedState;
use App\Enums\HospitalStayStatus;
use App\Models\HospitalBed;
use App\Models\HospitalRoom;
use App\Models\HospitalService;
use App\Models\HospitalStay;
use Illuminate\Support\Collection;

/**
 * ADR-164 — la lecture du référentiel des lits : ce qui existe, ce qui est
 * libre, qui occupe quoi. Une seule définition sert le portail, le plan des
 * lits de l'Hospitalisation et le choix d'un lit sur la page du séjour : deux
 * écrans ne peuvent pas compter différemment les lits libres.
 */
class BedDirectory
{
    /**
     * Le site a-t-il configuré ses lits ? Tant qu'il n'en a aucun, la chambre /
     * le lit restent saisis à la main (ADR-161) ; dès le premier lit, on choisit
     * dans le référentiel, et l'occupation est contrôlée.
     */
    public function configured(): bool
    {
        return HospitalBed::query()->inUse()->exists();
    }

    /**
     * Services › chambres › lits, dans l'ordre naturel (« Chambre 2 » avant
     * « Chambre 10 »).
     *
     * @param  bool  $withArchived  le portail peut relire ce qui a été archivé
     * @param  bool  $withPatients  le nom de l'occupant : servi à la clinique
     *                              seulement, le portail n'en a pas besoin
     * @return list<array<string, mixed>>
     */
    public function tree(bool $withArchived = false, bool $withPatients = false): array
    {
        $occupants = $this->occupants($withPatients);

        $services = HospitalService::query()
            ->when($withArchived, fn ($query) => $query->withTrashed())
            ->with(['rooms' => fn ($rooms) => $rooms->when($withArchived, fn ($query) => $query->withTrashed()),
                'rooms.beds' => fn ($beds) => $beds->when($withArchived, fn ($query) => $query->withTrashed())])
            ->get();

        return $this->natural($services, 'name')
            ->map(fn (HospitalService $service): array => [
                'uuid' => $service->uuid,
                'name' => $service->name,
                'care_level' => $service->care_level->value,
                'care_level_label' => $service->care_level->label(),
                'archived' => $service->trashed(),
                'archive_reason' => $service->delete_reason,
                'rooms' => $this->natural($service->rooms, 'name')
                    ->map(fn (HospitalRoom $room): array => [
                        'uuid' => $room->uuid,
                        'name' => $room->name,
                        'archived' => $room->trashed(),
                        'archive_reason' => $room->delete_reason,
                        'beds' => $this->natural($room->beds, 'label')
                            ->map(fn (HospitalBed $bed): array => $this->bed($bed, $occupants->get($bed->id)))
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array{services: int, rooms: int, beds: int, free: int, occupied: int, out_of_service: int} */
    public function summary(): array
    {
        $beds = HospitalBed::query()->inUse()->get(['id', 'out_of_service_at']);
        $occupied = HospitalStay::query()
            ->where('status', HospitalStayStatus::Active->value)
            ->whereIn('hospital_bed_id', $beds->pluck('id'))
            ->count();
        $outOfService = $beds->whereNotNull('out_of_service_at')->count();

        return [
            'services' => HospitalService::query()->count(),
            'rooms' => HospitalRoom::query()->whereHas('service', fn ($service) => $service->whereNull('hospital_services.deleted_at'))->count(),
            'beds' => $beds->count(),
            'free' => max(0, $beds->count() - $occupied - $outOfService),
            'occupied' => $occupied,
            'out_of_service' => $outOfService,
        ];
    }

    /**
     * Les lits que l'on peut attribuer maintenant, groupés par service puis
     * chambre, avec le niveau de soins que le lit donnera au séjour.
     *
     * @return list<array<string, mixed>>
     */
    public function freeBeds(): array
    {
        return collect($this->tree())
            ->map(function (array $service): array {
                $service['rooms'] = collect($service['rooms'])
                    ->map(function (array $room): array {
                        $room['beds'] = array_values(array_filter($room['beds'], fn (array $bed) => $bed['state'] === HospitalBedState::Free->value));

                        return $room;
                    })
                    ->filter(fn (array $room) => $room['beds'] !== [])
                    ->values()
                    ->all();

                return $service;
            })
            ->filter(fn (array $service) => $service['rooms'] !== [])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function bed(HospitalBed $bed, ?HospitalStay $stay): array
    {
        $state = match (true) {
            $stay !== null => HospitalBedState::Occupied,
            $bed->out_of_service_at !== null => HospitalBedState::OutOfService,
            default => HospitalBedState::Free,
        };

        return [
            'uuid' => $bed->uuid,
            'label' => $bed->label,
            'archived' => $bed->trashed(),
            'archive_reason' => $bed->delete_reason,
            'state' => $state->value,
            'state_label' => $state->label(),
            'out_of_service_at' => $bed->out_of_service_at?->toIso8601String(),
            'out_of_service_reason' => $bed->out_of_service_reason,
            'out_of_service_by' => $bed->external_out_of_service_by_name,
            'occupant' => $stay ? array_filter([
                'stay_uuid' => $stay->uuid,
                'episode_number' => $stay->episode?->episode_number,
                'admitted_at' => $stay->admitted_at?->toIso8601String(),
                'patient' => $stay->relationLoaded('episode') && $stay->episode?->relationLoaded('patient') && $stay->episode->patient
                    ? trim($stay->episode->patient->last_name.' '.$stay->episode->patient->first_name)
                    : null,
            ], fn ($value) => $value !== null) : null,
        ];
    }

    /** @return Collection<int, HospitalStay> keyed by bed id */
    private function occupants(bool $withPatients): Collection
    {
        return HospitalStay::query()
            ->where('status', HospitalStayStatus::Active->value)
            ->whereNotNull('hospital_bed_id')
            ->with($withPatients ? ['episode:id,episode_number,patient_id', 'episode.patient:id,first_name,last_name'] : ['episode:id,episode_number'])
            ->get(['id', 'uuid', 'episode_id', 'hospital_bed_id', 'admitted_at'])
            ->keyBy('hospital_bed_id');
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @return Collection<int, T>
     */
    private function natural(Collection $items, string $attribute): Collection
    {
        return $items->sortBy(fn ($item) => $item->{$attribute}, SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
