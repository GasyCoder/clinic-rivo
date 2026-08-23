<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\PrescriptionStatus;
use App\Models\EpisodeOrientation;
use App\Models\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicinePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');
        $prescription = $this->route('prescription');

        return $orientation instanceof EpisodeOrientation
            && $prescription instanceof Prescription
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $prescription->status === PrescriptionStatus::Active
            && $prescription->consultation?->episode_orientation_id === $orientation->getKey()
            && (bool) $this->user()?->can('prescriptions.update');
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('prescription_lines', 'id'),
            ],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.medication_name' => ['nullable', 'string', 'max:255'],
            'lines.*.dosage' => ['required', 'string', 'max:255'],
            'lines.*.frequency' => ['required', 'string', 'max:255'],
            'lines.*.duration' => ['nullable', 'string', 'max:255'],
            'lines.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'L’ordonnance doit conserver au moins une ligne.',
            'lines.*.id.exists' => 'Une ligne de cette ordonnance n’est plus disponible.',
            'lines.*.quantity.min' => 'La quantité doit être au moins égale à 1.',
            'lines.*.dosage.required' => 'Indiquez la dose (mode d’emploi) de ce médicament.',
            'lines.*.frequency.required' => 'Indiquez la fréquence de prise de ce médicament.',
        ];
    }
}
