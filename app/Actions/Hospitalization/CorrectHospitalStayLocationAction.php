<?php

namespace App\Actions\Hospitalization;

use App\Models\HospitalStay;
use App\Models\User;
use App\Support\Hospitalization\BedDirectory;
use App\Support\Hospitalization\HospitalBedAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-161 — corriger l'emplacement actuel : compléter la chambre à
 * l'admission, rectifier une erreur. Ce n'est pas une mutation : le mouvement
 * en cours est corrigé, l'ancienne valeur reste à l'audit. Un vrai changement
 * de service ou de lit passe par `MoveHospitalStayAction`.
 *
 * ADR-164 — attribuer le premier lit après l'admission automatique est ce
 * geste-là : le patient était déjà là, on dit enfin où. Le niveau de soins
 * suit le service du lit ; un lit occupé ou hors service est refusé.
 */
class CorrectHospitalStayLocationAction
{
    public function __construct(
        private readonly BedDirectory $beds,
        private readonly HospitalBedAllocator $allocator,
    ) {}

    /** @param array{hospital_bed_uuid?: ?string, service?: ?string, room_bed?: ?string} $data */
    public function execute(HospitalStay $stay, array $data, User $actor): HospitalStay
    {
        try {
            return DB::transaction(function () use ($stay, $data, $actor): HospitalStay {
                $locked = HospitalStay::query()->lockForUpdate()->findOrFail($stay->getKey());

                if (! $locked->isActive()) {
                    throw ValidationException::withMessages(['room_bed' => 'Le séjour est terminé.']);
                }

                $current = $locked->currentMovement()->lockForUpdate()->first();

                if ($this->beds->configured()) {
                    $uuid = trim((string) ($data[HospitalBedAllocator::FIELD] ?? ''));

                    if ($uuid === '') {
                        throw ValidationException::withMessages([HospitalBedAllocator::FIELD => 'Choisissez le lit du patient.']);
                    }

                    $bed = $this->allocator->lockFree($uuid, $locked);
                    $values = $this->allocator->snapshot($bed);
                    $movementValues = [...$values, 'care_level' => $bed->room->service->care_level];
                } else {
                    $clean = static fn (mixed $value): ?string => trim((string) $value) ?: null;
                    $values = [
                        'room_bed' => $clean($data['room_bed'] ?? null),
                        'service' => $clean($data['service'] ?? null),
                    ];
                    $movementValues = $values;
                }

                $locked->update($values);
                $current?->update([...$movementValues, 'updated_by' => $actor->getKey()]);

                return $locked->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw HospitalBedAllocator::takenMessage();
        }
    }
}
