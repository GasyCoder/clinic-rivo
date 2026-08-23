<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\PrescriptionStatus;
use App\Models\EpisodeOrientation;
use App\Models\Prescription;
use Illuminate\Foundation\Http\FormRequest;

class CancelMedicinePrescriptionRequest extends FormRequest
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
            && (bool) $this->user()?->can('prescriptions.cancel');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Indiquez le motif de l’annulation de l’ordonnance.'];
    }
}
