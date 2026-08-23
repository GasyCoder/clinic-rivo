<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicineDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('diagnoses.create');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DiagnosisType::class)],
            'description' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Saisissez l’hypothèse ou le diagnostic.',
        ];
    }
}
