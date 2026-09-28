<?php

namespace App\Http\Requests\Laboratory;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-214 — réceptionner une demande, et enregistrer ses prélèvements dans le même geste. */
class ReceiveLabRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('laboratory_orders.receive');
    }

    public function rules(): array
    {
        return LabSampleLinesRules::rules(false);
    }

    public function messages(): array
    {
        return LabSampleLinesRules::messages();
    }
}
