<?php

namespace App\Actions\Reception;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Patient\CreatePatientAction;
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
        private readonly CreateEpisodeAction $createEpisode,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, birth_date: string, sex: string, phone?: string|null, address?: string|null}|null  $newPatientData
     *         Required when $existingPatientId is null.
     *
     * @throws DuplicatePatientException see CreatePatientAction — only
     *         possible when registering a genuinely new patient.
     */
    public function execute(?int $existingPatientId, ?array $newPatientData, bool $confirmDuplicate = false): Episode
    {
        return DB::transaction(function () use ($existingPatientId, $newPatientData, $confirmDuplicate) {
            $patient = $existingPatientId
                ? Patient::findOrFail($existingPatientId)
                : $this->createPatient->execute($newPatientData, $confirmDuplicate);

            return $this->createEpisode->execute($patient);
        });
    }
}
