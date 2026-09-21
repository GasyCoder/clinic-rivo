<?php

namespace App\Actions\Hospitalization;

use App\Enums\HospitalCareLevel;
use App\Models\HospitalStay;
use App\Models\HospitalStayMovement;
use App\Models\User;
use App\Support\Hospitalization\BedDirectory;
use App\Support\Hospitalization\HospitalBedAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-161 — le patient change de service, de lit ou de niveau de soins.
 *
 * Le mouvement en cours se ferme, un nouveau s'ouvre : le séjour continue,
 * rien ne s'écrase. Une aggravation qui mène en réanimation ou en
 * surveillance continue est une mutation interne — la clinique en dispose.
 * Le service et le lit du séjour sont tenus à jour pour tous les écrans qui
 * les lisent déjà.
 *
 * ADR-164 — dès que le site a configuré ses lits, on choisit un lit libre du
 * référentiel : le service et le niveau de soins en découlent, et un lit déjà
 * occupé est refusé. Sans lit configuré, la saisie reste libre (ADR-161).
 */
class MoveHospitalStayAction
{
    public function __construct(
        private readonly BedDirectory $beds,
        private readonly HospitalBedAllocator $allocator,
    ) {}

    /** @param array{hospital_bed_uuid?: ?string, service?: ?string, room_bed?: ?string, care_level?: ?string, reason?: ?string} $data */
    public function execute(HospitalStay $stay, array $data, User $actor): HospitalStayMovement
    {
        try {
            return DB::transaction(fn (): HospitalStayMovement => $this->move($stay, $data, $actor));
        } catch (UniqueConstraintViolationException) {
            throw HospitalBedAllocator::takenMessage();
        }
    }

    private function move(HospitalStay $stay, array $data, User $actor): HospitalStayMovement
    {
        $locked = HospitalStay::query()->lockForUpdate()->findOrFail($stay->getKey());

        if (! $locked->isActive()) {
            throw ValidationException::withMessages(['service' => 'Le séjour est terminé.']);
        }

        $current = $locked->currentMovement()->lockForUpdate()->first();
        $location = $this->beds->configured()
            ? $this->bedLocation($locked, $data, $current)
            : $this->freeTextLocation($data, $current);

        $at = now();
        $locked->closeCurrentMovement($at);

        $movement = $locked->movements()->create([
            ...$location,
            'started_at' => $at,
            'reason' => $this->clean($data['reason'] ?? null),
            'moved_by' => $actor->getKey(),
        ]);

        $locked->update([
            'service' => $location['service'],
            'room_bed' => $location['room_bed'],
            'hospital_bed_id' => $location['hospital_bed_id'],
        ]);

        return $movement;
    }

    /** @return array{hospital_bed_id: int, service: string, room_bed: string, care_level: HospitalCareLevel} */
    private function bedLocation(HospitalStay $stay, array $data, ?HospitalStayMovement $current): array
    {
        $uuid = trim((string) ($data[HospitalBedAllocator::FIELD] ?? ''));

        if ($uuid === '') {
            throw ValidationException::withMessages([HospitalBedAllocator::FIELD => 'Choisissez le lit où installer le patient.']);
        }

        $bed = $this->allocator->lockFree($uuid, $stay);

        if ($stay->hospital_bed_id === $bed->getKey()) {
            throw ValidationException::withMessages([HospitalBedAllocator::FIELD => 'Le patient est déjà dans ce lit : choisissez-en un autre.']);
        }

        return [...$this->allocator->snapshot($bed), 'care_level' => $bed->room->service->care_level];
    }

    /** @return array{hospital_bed_id: null, service: ?string, room_bed: ?string, care_level: HospitalCareLevel} */
    private function freeTextLocation(array $data, ?HospitalStayMovement $current): array
    {
        $service = $this->clean($data['service'] ?? null);
        $roomBed = $this->clean($data['room_bed'] ?? null);

        if (blank($data['care_level'] ?? null)) {
            throw ValidationException::withMessages(['care_level' => 'Précisez le niveau de soins.']);
        }

        $careLevel = HospitalCareLevel::from($data['care_level']);

        // Une mutation qui ne change rien n'est pas un mouvement.
        if ($current
            && $current->service === $service
            && $current->room_bed === $roomBed
            && $current->care_level === $careLevel) {
            throw ValidationException::withMessages([
                'service' => 'Le patient est déjà à cet emplacement : modifiez le service, le lit ou le niveau de soins.',
            ]);
        }

        return ['hospital_bed_id' => null, 'service' => $service, 'room_bed' => $roomBed, 'care_level' => $careLevel];
    }

    private function clean(?string $value): ?string
    {
        return trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
