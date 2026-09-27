<?php

namespace App\Support\Hospitalization;

use App\Models\HospitalBed;
use App\Models\HospitalStay;
use Illuminate\Validation\ValidationException;

/**
 * ADR-164 — prendre un lit pour un séjour, sous verrou.
 *
 * Appelé dans la transaction de l'action qui installe le patient. Le lit est
 * verrouillé avant d'être relu : deux soignants qui choisissent le même lit au
 * même instant ne l'obtiennent pas tous les deux. L'index unique de
 * `bed_active_key` reste la garde finale, en base.
 */
class HospitalBedAllocator
{
    public const FIELD = 'hospital_bed_uuid';

    public function lockFree(string $bedUuid, HospitalStay $stay): HospitalBed
    {
        $bed = HospitalBed::query()
            ->inUse()
            ->with('room.service')
            ->where('uuid', $bedUuid)
            ->lockForUpdate()
            ->first();

        if ($bed === null) {
            throw ValidationException::withMessages([self::FIELD => 'Ce lit n’existe plus dans le référentiel du site.']);
        }

        if ($bed->out_of_service_at !== null) {
            throw ValidationException::withMessages([self::FIELD => "{$bed->locationLabel()} est hors service : choisissez un autre lit."]);
        }

        $occupant = $bed->activeStay()->with('episode.patient:id,first_name,last_name')->first();

        if ($occupant && ! $occupant->is($stay)) {
            $patient = $occupant->episode?->patient;

            throw ValidationException::withMessages([self::FIELD => sprintf(
                '%s est déjà occupé%s : choisissez un lit libre.',
                $bed->locationLabel(),
                $patient ? ' par '.trim($patient->last_name.' '.$patient->first_name) : '',
            )]);
        }

        return $bed;
    }

    /** Ce que le séjour et son emplacement retiennent du lit : un instantané. */
    public function snapshot(HospitalBed $bed): array
    {
        return [
            'hospital_bed_id' => $bed->getKey(),
            'service' => $bed->room->service->name,
            'room_bed' => $bed->locationLabel(),
        ];
    }

    public static function takenMessage(): ValidationException
    {
        return ValidationException::withMessages([self::FIELD => 'Ce lit vient d’être attribué à un autre patient : choisissez-en un autre.']);
    }
}
