<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelMedicineDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('diagnoses.update');
    }

    public function rules(): array
    {
        return [
            'diagnosis_id' => ['required', 'integer', 'exists:diagnoses,id'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var EpisodeOrientation $orientation */
            $orientation = $this->route('episodeOrientation');
            $diagnosis = Diagnosis::query()
                ->with(['consultation', 'cancellation'])
                ->find($this->integer('diagnosis_id'));

            if ($diagnosis?->consultation?->episode_orientation_id !== $orientation->getKey()) {
                $validator->errors()->add('diagnosis_id', 'Ce diagnostic n’appartient pas à cette consultation.');
            } elseif ($diagnosis->cancellation) {
                $validator->errors()->add('diagnosis_id', 'Ce diagnostic est déjà annulé.');
            } elseif ($diagnosis->recorded_by !== $this->user()?->getKey()) {
                $validator->errors()->add('diagnosis_id', 'Seul l’auteur de ce diagnostic peut l’annuler.');
            }
        }];
    }
}
