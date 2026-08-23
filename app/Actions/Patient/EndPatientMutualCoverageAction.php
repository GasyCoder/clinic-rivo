<?php

namespace App\Actions\Patient;

use App\Models\PatientMutualCoverage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EndPatientMutualCoverageAction
{
    public function execute(PatientMutualCoverage $coverage, string $reason, User $actor): PatientMutualCoverage
    {
        if ($actor->cannot('patient_coverages.end')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver cette couverture.');
        }

        $reason = str($reason)->squish()->toString();

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif d’archivage est obligatoire et limité à 1000 caractères.',
            ]);
        }

        return DB::transaction(function () use ($coverage, $reason, $actor) {
            $coverage = PatientMutualCoverage::query()->lockForUpdate()->findOrFail($coverage->id);

            if (! $coverage->isActive()) {
                throw ValidationException::withMessages([
                    'coverage' => 'Cette couverture est déjà archivée.',
                ]);
            }

            $coverage->fill([
                'ended_by' => $actor->id,
                'effective_until' => now(),
                'end_reason' => $reason,
            ])->save();

            return $coverage->refresh();
        });
    }
}
