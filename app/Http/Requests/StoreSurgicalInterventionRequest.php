<?php

namespace App\Http\Requests;

use App\Models\SurgicalRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurgicalInterventionRequest extends FormRequest
{
    /**
     * ADR-170 — la permission de route ouvre le métier ; la Policy ouvre le
     * dossier. `CreateSurgicalInterventionAction` revérifie tout sous verrou.
     */
    public function authorize(): bool
    {
        $case = $this->route('surgicalRequest');

        return $case instanceof SurgicalRequest
            && $this->user()?->can('startIntervention', $case) === true;
    }

    public function rules(): array
    {
        return [
            'performed_by' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deactivated_at')),
            ],
            'started_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
