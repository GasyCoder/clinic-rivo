<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\HospitalCareLevel;
use App\Http\Controllers\Controller;
use App\Models\HospitalBed;
use App\Models\HospitalRoom;
use App\Models\HospitalService;
use App\Services\Catalog\CatalogActor;
use App\Services\Hospitalization\HospitalBedReferential;
use App\Support\Hospitalization\BedDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-164 — services, chambres et lits du site, réglés depuis le portail.
 *
 * Le portail n'écrit jamais dans cette base : chaque commande arrive ici avec
 * l'identité du Super Administrateur, réautorisée localement, idempotente et
 * auditée (ADR-004/027). L'occupation se lit sur les séjours du site ; le
 * portail n'en reçoit que le passage et la date d'entrée, jamais le nom du
 * patient.
 */
class HospitalBedController extends Controller
{
    public function index(Request $request, BedDirectory $beds): JsonResponse
    {
        $this->actor($request, 'hospital_beds.view');
        $archived = $request->boolean('archived');

        return response()->json([
            'data' => $beds->tree(withArchived: $archived),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'summary' => $beds->summary(),
                'max_beds_per_room' => HospitalBedReferential::MAX_BEDS_PER_ROOM,
                'care_levels' => collect(HospitalCareLevel::cases())
                    ->map(fn (HospitalCareLevel $level): array => ['value' => $level->value, 'label' => $level->label()])
                    ->all(),
            ],
        ]);
    }

    public function storeService(Request $request, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.create');
        $validated = $this->validateService($request);
        $service = $referential->createService($validated['name'], $validated['care_level'], $actor);

        return response()->json(['message' => "Service « {$service->name} » créé.", 'data' => ['uuid' => $service->uuid]], 201);
    }

    public function updateService(Request $request, string $serviceUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.update');
        $validated = $this->validateService($request);
        $service = $referential->updateService($this->service($serviceUuid), $validated['name'], $validated['care_level'], $actor);

        return response()->json(['message' => "Service « {$service->name} » mis à jour."]);
    }

    public function archiveService(Request $request, string $serviceUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.archive');
        $service = $this->service($serviceUuid);
        $referential->archiveService($service, $this->reason($request), $actor);

        return response()->json(['message' => "Service « {$service->name} » archivé : ses chambres et ses lits ne sont plus proposés."]);
    }

    public function restoreService(Request $request, string $serviceUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.restore');
        $service = $referential->restoreService($this->service($serviceUuid, trashed: true), $actor);

        return response()->json(['message' => "Service « {$service->name} » restauré."]);
    }

    public function storeRoom(Request $request, string $serviceUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.create');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'bed_count' => ['required', 'integer', 'min:1', 'max:'.HospitalBedReferential::MAX_BEDS_PER_ROOM],
        ], ['bed_count.max' => 'Une chambre compte au plus :max lits.']);
        $room = $referential->createRoom($this->service($serviceUuid), $validated['name'], (int) $validated['bed_count'], $actor);
        $count = $room->beds->count();

        return response()->json([
            'message' => "Chambre « {$room->name} » créée avec {$count} lit".($count > 1 ? 's' : '').'.',
            'data' => ['uuid' => $room->uuid],
        ], 201);
    }

    public function updateRoom(Request $request, string $roomUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.update');
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $room = $referential->updateRoom($this->room($roomUuid), $validated['name'], $actor);

        return response()->json(['message' => "Chambre « {$room->name} » renommée."]);
    }

    public function addBeds(Request $request, string $roomUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.create');
        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.HospitalBedReferential::MAX_BEDS_PER_ROOM],
        ]);
        $room = $referential->addBeds($this->room($roomUuid), (int) $validated['count'], $actor);
        $count = (int) $validated['count'];

        return response()->json(['message' => "{$count} lit".($count > 1 ? 's' : '').' ajouté'.($count > 1 ? 's' : '')." à « {$room->name} »."]);
    }

    public function archiveRoom(Request $request, string $roomUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.archive');
        $room = $this->room($roomUuid);
        $referential->archiveRoom($room, $this->reason($request), $actor);

        return response()->json(['message' => "Chambre « {$room->name} » archivée : ses lits ne sont plus proposés."]);
    }

    public function restoreRoom(Request $request, string $roomUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.restore');
        $room = $referential->restoreRoom($this->room($roomUuid, trashed: true), $actor);

        return response()->json(['message' => "Chambre « {$room->name} » restaurée."]);
    }

    public function updateBed(Request $request, string $bedUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.update');
        $validated = $request->validate(['label' => ['required', 'string', 'max:50']]);
        $bed = $referential->updateBed($this->bed($bedUuid), $validated['label'], $actor);

        return response()->json(['message' => "Lit renommé « {$bed->label} »."]);
    }

    public function outOfService(Request $request, string $bedUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.update');
        $bed = $referential->putOutOfService($this->bed($bedUuid), $this->reason($request), $actor);

        return response()->json(['message' => "{$bed->locationLabel()} mis hors service : il n’est plus proposé."]);
    }

    public function inService(Request $request, string $bedUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.update');
        $bed = $referential->putBackInService($this->bed($bedUuid), $actor);

        return response()->json(['message' => "{$bed->locationLabel()} remis en service."]);
    }

    public function archiveBed(Request $request, string $bedUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.archive');
        $bed = $this->bed($bedUuid);
        $referential->archiveBed($bed, $this->reason($request), $actor);

        return response()->json(['message' => "{$bed->locationLabel()} archivé."]);
    }

    public function restoreBed(Request $request, string $bedUuid, HospitalBedReferential $referential): JsonResponse
    {
        $actor = $this->actor($request, 'hospital_beds.restore');
        $bed = $referential->restoreBed($this->bed($bedUuid, trashed: true), $actor);

        return response()->json(['message' => "{$bed->locationLabel()} restauré."]);
    }

    /** @return array{name: string, care_level: string} */
    private function validateService(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'care_level' => ['required', Rule::enum(HospitalCareLevel::class)],
        ]);
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }

    private function service(string $uuid, bool $trashed = false): HospitalService
    {
        return HospitalService::query()
            ->when($trashed, fn ($query) => $query->onlyTrashed())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function room(string $uuid, bool $trashed = false): HospitalRoom
    {
        return HospitalRoom::query()
            ->when($trashed, fn ($query) => $query->onlyTrashed())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function bed(string $uuid, bool $trashed = false): HospitalBed
    {
        return HospitalBed::query()
            ->when($trashed, fn ($query) => $query->onlyTrashed())
            ->with('room')
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function actor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }
}
