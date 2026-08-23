<?php

namespace App\Actions\Patient;

use App\Enums\MutualBeneficiaryType;
use App\Enums\PatientType;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\PatientMutualCoverage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class CreatePatientMutualCoverageAction
{
    /** @param array{mutual_organization_uuid: string, employer_name: string, beneficiary_type: string, membership_number: string} $data */
    public function execute(Patient $patient, array $data, User $actor): PatientMutualCoverage
    {
        if ($actor->cannot('patient_coverages.create')) {
            throw new AuthorizationException('Vous ne pouvez pas enregistrer une couverture mutuelle.');
        }

        $validated = validator($data, [
            'mutual_organization_uuid' => ['required', 'uuid'],
            'employer_name' => ['required', 'string', 'max:255'],
            'beneficiary_type' => ['required', new Enum(MutualBeneficiaryType::class)],
            'membership_number' => ['required', 'string', 'max:100'],
        ])->validate();

        return DB::transaction(function () use ($patient, $validated, $actor) {
            $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if ($patient->patient_type !== PatientType::Mutual) {
                throw ValidationException::withMessages([
                    'patient_type' => 'Le dossier doit être de type Mutualiste.',
                ]);
            }

            $organization = MutualOrganization::query()
                ->where('uuid', $validated['mutual_organization_uuid'])
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $organization) {
                throw ValidationException::withMessages([
                    'mutual_organization_uuid' => 'La mutuelle sélectionnée est indisponible ou archivée.',
                ]);
            }

            if (PatientMutualCoverage::query()->active()->where('patient_id', $patient->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'mutual_organization_uuid' => 'Ce patient possède déjà une couverture mutuelle active.',
                ]);
            }

            return PatientMutualCoverage::create([
                'patient_id' => $patient->id,
                'mutual_organization_id' => $organization->id,
                'employer_name' => str($validated['employer_name'])->squish()->toString(),
                'beneficiary_type' => $validated['beneficiary_type'],
                'membership_number' => str($validated['membership_number'])->squish()->toString(),
                'created_by' => $actor->id,
                'effective_from' => now(),
            ]);
        });
    }
}
