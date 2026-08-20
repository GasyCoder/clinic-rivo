<?php

namespace App\Actions\Reception;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Patient\CreatePatientAction;
use App\Actions\Patient\UpdatePatientAction;
use App\Enums\EpisodePriority;
use App\Exceptions\DuplicatePatientException;
use App\Models\Episode;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * CDC §5.2.1: "rechercher un patient / créer un patient / créer un passage
 * / orienter" are one Réception function, not separate steps a
 * receptionist triggers independently — a patient is never created in the
 * abstract, only because they've just arrived for a visit. This is the
 * single entry point for that: reuse an existing patient (found by the
 * receptionist beforehand) or create a new one, then always open their
 * episode, in one transaction.
 */
class RegisterArrivalAction
{
    public function __construct(
        private readonly CreatePatientAction $createPatient,
        private readonly UpdatePatientAction $updatePatient,
        private readonly CreateEpisodeAction $createEpisode,
    ) {}

    /**
     * Existing-patient corrections are applied before opening the episode.
     *
     * @param  array<string, mixed>|null  $newPatientData
     * @param  array<string, mixed>|null  $existingPatientData
     *
     * @throws DuplicatePatientException for an unconfirmed new-patient match
     */
    public function execute(
        ?string $existingPatientUuid,
        ?array $newPatientData,
        ?array $existingPatientData = null,
        bool $confirmDuplicate = false,
        EpisodePriority $priority = EpisodePriority::Normal,
    ): Episode {
        return DB::transaction(function () use ($existingPatientUuid, $newPatientData, $existingPatientData, $confirmDuplicate, $priority) {
            if ($existingPatientUuid) {
                $patient = Patient::query()->where('uuid', $existingPatientUuid)->firstOrFail();

                if ($existingPatientData !== null) {
                    $patient = $this->updatePatient->execute($patient, $existingPatientData);
                }
            } else {
                $patient = $this->createPatient->execute($newPatientData, $confirmDuplicate);
            }

            return $this->createEpisode->execute($patient, $priority);
        });
    }
}
