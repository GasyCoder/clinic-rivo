<?php

namespace App\Http\Requests;

use App\Actions\Patient\StorePatientMutualCoverageAttachmentsAction;
use App\Models\Patient;
use App\Models\PatientMutualCoverage;
use Illuminate\Foundation\Http\FormRequest;

class StorePatientMutualCoverageAttachmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');
        $coverage = $this->route('mutualCoverage');
        $user = $this->user();

        return $user !== null
            && $patient instanceof Patient
            && $coverage instanceof PatientMutualCoverage
            && $coverage->patient_id === $patient->id
            && $user->can('patients.update')
            && $user->can('patient_coverages.view')
            && $user->can('patient_coverage_documents.create');
    }

    public function rules(): array
    {
        return [
            'files' => [
                'required',
                'array',
                'min:1',
                'max:'.StorePatientMutualCoverageAttachmentsAction::MAX_FILES,
            ],
            'files.*' => [
                'bail',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:5120',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'files' => 'justificatifs de mutuelle',
            'files.*' => 'justificatif de mutuelle',
        ];
    }
}
