<?php

namespace App\Http\Requests\Laboratory;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-214 — ajouter des prélèvements à une demande reçue (un tube de plus, un tube refait). */
class StoreLabSamplesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('laboratory_samples.create');
    }

    public function rules(): array
    {
        return LabSampleLinesRules::rules(true);
    }

    public function messages(): array
    {
        return LabSampleLinesRules::messages();
    }
}
