<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\ClinicalPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-113 (amendement) — la demande d'hospitalisation se complète dans le
 * module Hospitalisation, pas dans la consultation. Ses champs médicaux
 * restent sous `hospitalization.request` : c'est le médecin qui les signe.
 */
class UpdateHospitalizationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hospitalization.request');
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:3000'],
            'admission_diagnosis' => ['nullable', 'string', 'max:3000'],
            'clinical_summary' => ['nullable', 'string', 'max:5000'],
            'planned_treatment' => ['nullable', 'string', 'max:3000'],
            'priority' => ['required', 'string', Rule::in(array_column(ClinicalPriority::cases(), 'value'))],
            'instructions' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
