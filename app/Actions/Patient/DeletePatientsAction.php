<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes patients one model at a time so the SoftDeletable concern
 * records deleted_by, the reason, and one audit entry for every patient.
 */
class DeletePatientsAction
{
    /**
     * @param  iterable<int, Patient>  $patients
     */
    public function execute(iterable $patients, string $reason): int
    {
        return DB::transaction(function () use ($patients, $reason) {
            $deleted = 0;

            foreach ($patients as $patient) {
                $patient->delete_reason = $reason;
                $patient->delete();
                $deleted++;
            }

            return $deleted;
        });
    }
}
