<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;

class RestorePatientAction
{
    public function execute(Patient $patient): Patient
    {
        return DB::transaction(function () use ($patient): Patient {
            if ($patient->trashed()) {
                $patient->restore();
            }

            return $patient->refresh();
        });
    }
}
