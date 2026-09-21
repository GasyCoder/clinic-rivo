<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\ClinicalPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ADR-113 (amendement) — la demande d'hospitalisation se complète dans le
 * module Hospitalisation, pas dans la consultation. Ses champs médicaux
 * restent sous `hospitalization.request` : c'est le médecin qui les signe.
 *
 * Chaque rubrique se corrige seule (un crayon par rubrique) : un champ absent
 * de l'envoi n'est pas touché — omettre n'efface pas (ADR-074). Un champ envoyé
 * vide, lui, efface la rubrique. Un envoi sans aucune rubrique est refusé.
 */
class UpdateHospitalizationRequestRequest extends FormRequest
{
    /** Les rubriques de la demande, dans l'ordre de la carte. */
    public const FIELDS = ['reason', 'admission_diagnosis', 'clinical_summary', 'planned_treatment', 'priority', 'instructions'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hospitalization.request');
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'admission_diagnosis' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'clinical_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'planned_treatment' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'priority' => ['sometimes', 'required', 'string', Rule::in(array_column(ClinicalPriority::cases(), 'value'))],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('hospitalization_request', 'Aucune rubrique de la demande n’a été envoyée.');
                }
            },
        ];
    }
}
