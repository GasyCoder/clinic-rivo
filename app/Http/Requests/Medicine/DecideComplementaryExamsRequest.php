<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;

class DecideComplementaryExamsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('consultations.update');
    }

    public function rules(): array
    {
        return [
            // Required here, unlike on the examination: this endpoint exists
            // only to answer the question, so an empty call means nothing.
            'required' => ['required', 'boolean'],
            // Set once the doctor has confirmed that answering "non"
            // withdraws the requests already sent.
            'withdraw_confirmed' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required.required' => 'Indiquez si des examens complémentaires sont nécessaires.',
        ];
    }
}
