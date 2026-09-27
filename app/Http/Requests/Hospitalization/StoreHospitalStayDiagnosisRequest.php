<?php

namespace App\Http\Requests\Hospitalization;

use App\Models\HospitalStay;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-147 — poser un diagnostic au terme d'un séjour encore en cours.
 *
 * Même droit que le diagnostic d'une consultation (`diagnoses.create`, ADR-035) :
 * conclure un séjour n'est pas une autre autorité que conclure une rencontre.
 */
class StoreHospitalStayDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        $stay = $this->route('hospitalStay');

        return $stay instanceof HospitalStay
            && $stay->isActive()
            && (bool) $this->user()?->can('diagnoses.create');
    }

    public function rules(): array
    {
        return [
            'diagnostic_catalog_uuid' => ['nullable', 'uuid', 'prohibits:description'],
            'description' => ['nullable', 'required_without:diagnostic_catalog_uuid', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
