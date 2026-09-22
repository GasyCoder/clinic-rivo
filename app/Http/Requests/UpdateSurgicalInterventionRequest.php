<?php

namespace App\Http\Requests;

use App\Models\SurgicalRequest;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSurgicalInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('surgicalRequest');

        return $case instanceof SurgicalRequest
            && $this->user()?->can('updateIntervention', $case) === true;
    }

    public function rules(): array
    {
        return [
            'ended_at' => ['nullable', 'date'],
            'procedure_summary' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
