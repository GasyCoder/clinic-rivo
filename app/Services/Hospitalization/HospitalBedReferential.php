<?php

namespace App\Services\Hospitalization;

use App\Enums\HospitalCareLevel;
use App\Enums\HospitalStayStatus;
use App\Models\HospitalBed;
use App\Models\HospitalRoom;
use App\Models\HospitalService;
use App\Models\HospitalStay;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-164 — le référentiel des services, chambres et lits d'un site.
 *
 * Réglé depuis le portail, exécuté dans la base du site (ADR-004). Deux règles
 * tiennent tout le reste :
 *
 *   - un lit occupé ne quitte pas l'usage : ni hors service, ni archivé, ni sa
 *     chambre, ni son service — le patient change d'abord de lit ;
 *   - rien ne se détruit : on archive avec un motif, on restaure (ADR-009).
 *
 * Les noms se comparent sans accents, espaces ni majuscules, archivés compris :
 * un nom archivé se restaure, il ne se recrée pas en doublon.
 */
class HospitalBedReferential
{
    public const MAX_BEDS_PER_ROOM = 30;

    public function createService(string $name, string $careLevel, CatalogActor $actor): HospitalService
    {
        $this->authorize($actor, 'hospital_beds.create');
        $name = $this->name($name, 150, 'name', 'Le nom du service');

        return DB::transaction(function () use ($name, $careLevel): HospitalService {
            $this->ensureServiceNameFree($name);

            return HospitalService::query()->create([
                'name' => $name,
                'care_level' => HospitalCareLevel::from($careLevel),
            ]);
        });
    }

    /**
     * Changer le niveau de soins d'un service vaut pour les installations à
     * venir : les patients déjà dans ses lits gardent le niveau figé sur leur
     * emplacement, comme tout instantané du séjour.
     */
    public function updateService(HospitalService $service, string $name, string $careLevel, CatalogActor $actor): HospitalService
    {
        $this->authorize($actor, 'hospital_beds.update');
        $name = $this->name($name, 150, 'name', 'Le nom du service');

        return DB::transaction(function () use ($service, $name, $careLevel): HospitalService {
            $this->ensureServiceNameFree($name, $service);
            $service->update(['name' => $name, 'care_level' => HospitalCareLevel::from($careLevel)]);

            return $service->refresh();
        });
    }

    public function archiveService(HospitalService $service, string $reason, CatalogActor $actor): void
    {
        $this->authorize($actor, 'hospital_beds.archive');
        $reason = $this->reason($reason);

        DB::transaction(function () use ($service, $reason): void {
            $service = HospitalService::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
            $occupied = $this->occupiedStays(fn ($beds) => $beds->whereHas('room', fn ($room) => $room->where('hospital_service_id', $service->getKey())));

            if ($occupied > 0) {
                throw ValidationException::withMessages([
                    'service' => sprintf('%d lit%s de ce service %s occupé%s : installez d’abord ces patients ailleurs.', $occupied, $occupied > 1 ? 's' : '', $occupied > 1 ? 'sont' : 'est', $occupied > 1 ? 's' : ''),
                ]);
            }

            $service->delete_reason = $reason;
            $service->delete();
        });
    }

    public function restoreService(HospitalService $service, CatalogActor $actor): HospitalService
    {
        $this->authorize($actor, 'hospital_beds.restore');

        if (HospitalService::query()->where('normalized_name', $service->normalized_name)->whereKeyNot($service->getKey())->exists()) {
            throw ValidationException::withMessages(['service' => 'Un service actif porte déjà ce nom : la restauration est impossible.']);
        }

        $service->restore();

        return $service->refresh();
    }

    public function createRoom(HospitalService $service, string $name, int $bedCount, CatalogActor $actor): HospitalRoom
    {
        $this->authorize($actor, 'hospital_beds.create');
        $name = $this->name($name, 100, 'name', 'Le nom de la chambre');
        $this->ensureBedCount($bedCount, 'bed_count');

        return DB::transaction(function () use ($service, $name, $bedCount): HospitalRoom {
            $service = HospitalService::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureRoomNameFree($service, $name);

            $room = $service->rooms()->create(['name' => $name]);
            $this->createBeds($room, $bedCount);

            return $room->load('beds');
        });
    }

    public function updateRoom(HospitalRoom $room, string $name, CatalogActor $actor): HospitalRoom
    {
        $this->authorize($actor, 'hospital_beds.update');
        $name = $this->name($name, 100, 'name', 'Le nom de la chambre');

        return DB::transaction(function () use ($room, $name): HospitalRoom {
            $this->ensureRoomNameFree($room->service, $name, $room);
            $room->update(['name' => $name]);

            return $room->refresh();
        });
    }

    /** Ajoute des lits à la suite des numéros déjà pris dans la chambre. */
    public function addBeds(HospitalRoom $room, int $count, CatalogActor $actor): HospitalRoom
    {
        $this->authorize($actor, 'hospital_beds.create');
        $this->ensureBedCount($count, 'count');

        return DB::transaction(function () use ($room, $count): HospitalRoom {
            $room = HospitalRoom::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();
            $inUse = $room->beds()->count();

            if ($inUse + $count > self::MAX_BEDS_PER_ROOM) {
                throw ValidationException::withMessages([
                    'count' => sprintf('Une chambre compte au plus %d lits : celle-ci en a déjà %d.', self::MAX_BEDS_PER_ROOM, $inUse),
                ]);
            }

            $this->createBeds($room, $count);

            return $room->load('beds');
        });
    }

    public function archiveRoom(HospitalRoom $room, string $reason, CatalogActor $actor): void
    {
        $this->authorize($actor, 'hospital_beds.archive');
        $reason = $this->reason($reason);

        DB::transaction(function () use ($room, $reason): void {
            $room = HospitalRoom::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            if ($this->occupiedStays(fn ($beds) => $beds->where('hospital_room_id', $room->getKey())) > 0) {
                throw ValidationException::withMessages([
                    'room' => 'Un patient occupe un lit de cette chambre : installez-le d’abord ailleurs.',
                ]);
            }

            $room->delete_reason = $reason;
            $room->delete();
        });
    }

    public function restoreRoom(HospitalRoom $room, CatalogActor $actor): HospitalRoom
    {
        $this->authorize($actor, 'hospital_beds.restore');

        if ($room->service?->trashed()) {
            throw ValidationException::withMessages(['room' => 'Son service est archivé : restaurez d’abord le service.']);
        }

        if (HospitalRoom::query()
            ->where('hospital_service_id', $room->hospital_service_id)
            ->where('normalized_name', $room->normalized_name)
            ->whereKeyNot($room->getKey())
            ->exists()) {
            throw ValidationException::withMessages(['room' => 'Une chambre active du service porte déjà ce nom.']);
        }

        $room->restore();

        return $room->refresh();
    }

    public function updateBed(HospitalBed $bed, string $label, CatalogActor $actor): HospitalBed
    {
        $this->authorize($actor, 'hospital_beds.update');
        $label = $this->name($label, 50, 'label', 'Le nom du lit');

        return DB::transaction(function () use ($bed, $label): HospitalBed {
            $this->ensureBedLabelFree($bed->room, $label, $bed);
            $bed->update(['label' => $label]);

            return $bed->refresh();
        });
    }

    public function putOutOfService(HospitalBed $bed, string $reason, CatalogActor $actor): HospitalBed
    {
        $this->authorize($actor, 'hospital_beds.update');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($bed, $reason, $actor): HospitalBed {
            $bed = HospitalBed::query()->whereKey($bed->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureFree($bed, 'Ce lit est occupé : installez d’abord le patient dans un autre lit.');

            $bed->update([
                'out_of_service_at' => now(),
                'out_of_service_reason' => $reason,
                'out_of_service_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('out_of_service'));

            return $bed->refresh();
        });
    }

    public function putBackInService(HospitalBed $bed, CatalogActor $actor): HospitalBed
    {
        $this->authorize($actor, 'hospital_beds.update');

        $bed->update([
            'out_of_service_at' => null,
            'out_of_service_reason' => null,
            'out_of_service_by' => null,
            'external_out_of_service_by_uuid' => null,
            'external_out_of_service_by_name' => null,
        ]);

        return $bed->refresh();
    }

    public function archiveBed(HospitalBed $bed, string $reason, CatalogActor $actor): void
    {
        $this->authorize($actor, 'hospital_beds.archive');
        $reason = $this->reason($reason);

        DB::transaction(function () use ($bed, $reason): void {
            $bed = HospitalBed::query()->whereKey($bed->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureFree($bed, 'Ce lit est occupé : installez d’abord le patient dans un autre lit.');

            $bed->delete_reason = $reason;
            $bed->delete();
        });
    }

    public function restoreBed(HospitalBed $bed, CatalogActor $actor): HospitalBed
    {
        $this->authorize($actor, 'hospital_beds.restore');

        if ($bed->room?->trashed()) {
            throw ValidationException::withMessages(['bed' => 'Sa chambre est archivée : restaurez d’abord la chambre.']);
        }

        if ($bed->room->beds()->count() >= self::MAX_BEDS_PER_ROOM) {
            throw ValidationException::withMessages(['bed' => sprintf('La chambre compte déjà %d lits.', self::MAX_BEDS_PER_ROOM)]);
        }

        $bed->restore();

        return $bed->refresh();
    }

    /** « Lit 1 » … « Lit N », à la suite de tout nom déjà pris, archivés compris. */
    private function createBeds(HospitalRoom $room, int $count): void
    {
        $taken = HospitalBed::withTrashed()->where('hospital_room_id', $room->getKey())->pluck('normalized_label')->flip();
        $number = 1;

        for ($created = 0; $created < $count; $number++) {
            $label = 'Lit '.$number;

            if ($taken->has(HospitalService::normalize($label))) {
                continue;
            }

            $room->beds()->create(['label' => $label]);
            $created++;
        }
    }

    /** @param callable(Builder<HospitalBed>): mixed $scope */
    private function occupiedStays(callable $scope): int
    {
        return HospitalStay::query()
            ->where('status', HospitalStayStatus::Active->value)
            ->whereHas('bed', function ($beds) use ($scope): void {
                $scope($beds);
            })
            ->count();
    }

    private function ensureFree(HospitalBed $bed, string $message): void
    {
        if ($bed->activeStay()->exists()) {
            throw ValidationException::withMessages(['bed' => $message]);
        }
    }

    private function ensureServiceNameFree(string $name, ?HospitalService $except = null): void
    {
        $existing = HospitalService::withTrashed()
            ->where('normalized_name', HospitalService::normalize($name))
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->lockForUpdate()
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'name' => $existing->trashed()
                    ? 'Un service archivé porte ce nom : restaurez-le au lieu d’en créer un nouveau.'
                    : 'Un service porte déjà ce nom.',
            ]);
        }
    }

    private function ensureRoomNameFree(HospitalService $service, string $name, ?HospitalRoom $except = null): void
    {
        $existing = HospitalRoom::withTrashed()
            ->where('hospital_service_id', $service->getKey())
            ->where('normalized_name', HospitalService::normalize($name))
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'name' => $existing->trashed()
                    ? 'Une chambre archivée de ce service porte ce nom : restaurez-la.'
                    : 'Une chambre de ce service porte déjà ce nom.',
            ]);
        }
    }

    private function ensureBedLabelFree(HospitalRoom $room, string $label, HospitalBed $except): void
    {
        $existing = HospitalBed::withTrashed()
            ->where('hospital_room_id', $room->getKey())
            ->where('normalized_label', HospitalService::normalize($label))
            ->whereKeyNot($except->getKey())
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'label' => $existing->trashed()
                    ? 'Un lit archivé de cette chambre porte ce nom.'
                    : 'Un lit de cette chambre porte déjà ce nom.',
            ]);
        }
    }

    private function ensureBedCount(int $count, string $field): void
    {
        if ($count < 1 || $count > self::MAX_BEDS_PER_ROOM) {
            throw ValidationException::withMessages([
                $field => sprintf('Le nombre de lits est compris entre 1 et %d.', self::MAX_BEDS_PER_ROOM),
            ]);
        }
    }

    private function name(string $value, int $max, string $field, string $what): string
    {
        $value = str($value)->squish()->toString();

        if ($value === '' || mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => sprintf('%s contient entre 1 et %d caractères.', $what, $max)]);
        }

        return $value;
    }

    private function reason(string $reason): string
    {
        $reason = str($reason)->squish()->toString();

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Le motif contient entre 5 et 500 caractères.']);
        }

        return $reason;
    }

    private function authorize(CatalogActor $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action sur les lits n’est pas autorisée.');
        }
    }
}
