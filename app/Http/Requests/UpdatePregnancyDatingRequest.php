<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\PregnancyDatingMethod;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePregnancyDatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Maternity
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->episode()->whereHas('maternityRecord', fn ($query) => $query->whereNotNull('pregnancy_id'))->exists()
            && (bool) $this->user()?->can('maternity.update')
            && (bool) $this->user()?->can('maternity.prenatal.manage');
    }

    public function rules(): array
    {
        $method = $this->input('dating_method');

        return [
            'dating_method' => ['required', Rule::enum(PregnancyDatingMethod::class)],
            'last_menstrual_period' => [
                Rule::requiredIf($method === PregnancyDatingMethod::LastMenstrualPeriod->value),
                'nullable', 'date', 'before_or_equal:today',
            ],
            'estimated_due_date' => [
                Rule::requiredIf(in_array($method, [
                    PregnancyDatingMethod::Ultrasound->value,
                    PregnancyDatingMethod::ManualCorrection->value,
                ], true)),
                'nullable', 'date',
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
