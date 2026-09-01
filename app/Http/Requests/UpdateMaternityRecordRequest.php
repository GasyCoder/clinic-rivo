<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaternityRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');
        if (! $orientation instanceof EpisodeOrientation
            || $orientation->destination_module !== CatalogModule::Maternity
            || $orientation->status !== EpisodeOrientationStatus::InProgress) {
            return false;
        }

        $exists = $orientation->episode()->whereHas('maternityRecord')->exists();

        return (bool) $this->user()?->can($exists ? 'maternity.update' : 'maternity.create');
    }

    public function rules(): array
    {
        $canPrenatal = (bool) $this->user()?->can('maternity.prenatal.manage');
        $canLabor = (bool) $this->user()?->can('maternity.labor.manage');
        $canDelivery = (bool) $this->user()?->can('maternity.delivery.manage');
        $canNewborn = (bool) $this->user()?->can('maternity.newborn.manage');

        return [
            'obstetric_context' => ['nullable', 'string', 'max:5000'],
            'pregnancy_data' => ['nullable', 'array'],
            'pregnancy_data.gravidity' => ['nullable', 'integer', 'min:0', 'max:30'],
            'pregnancy_data.parity' => ['nullable', 'integer', 'min:0', 'max:30'],
            'pregnancy_data.last_menstrual_period' => ['nullable', 'date', 'before_or_equal:today'],
            'pregnancy_data.estimated_due_date' => ['nullable', 'date'],
            'pregnancy_data.risk_factors' => ['nullable', 'string', 'max:3000'],
            'prenatal_data' => [Rule::prohibitedIf(! $canPrenatal), 'nullable', 'array'],
            'prenatal_data.gestational_age_weeks' => ['nullable', 'integer', 'min:0', 'max:45'],
            'prenatal_data.fundal_height_cm' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'prenatal_data.fetal_heart_rate' => ['nullable', 'integer', 'min:40', 'max:250'],
            'prenatal_data.notes' => ['nullable', 'string', 'max:5000'],
            'labor_data' => [Rule::prohibitedIf(! $canLabor), 'nullable', 'array'],
            'labor_data.started_at' => ['nullable', 'date'],
            'labor_data.membranes_status' => ['nullable', Rule::in(['INTACT', 'RUPTURED', 'UNKNOWN'])],
            'labor_data.cervical_dilation_cm' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'labor_data.contractions' => ['nullable', 'string', 'max:1000'],
            'labor_data.surveillance_notes' => ['nullable', 'string', 'max:5000'],
            'delivery_data' => [Rule::prohibitedIf(! $canDelivery), 'nullable', 'array'],
            'delivery_data.occurred_at' => ['nullable', 'date'],
            'delivery_data.mode' => ['nullable', Rule::in(['VAGINAL', 'INSTRUMENTAL', 'CESAREAN'])],
            'delivery_data.placenta_status' => ['nullable', 'string', 'max:1000'],
            'delivery_data.complications' => ['nullable', 'string', 'max:5000'],
            'newborn_data' => [Rule::prohibitedIf(! $canNewborn), 'nullable', 'array'],
            'newborn_data.newborns' => ['nullable', 'array', 'max:5'],
            'newborn_data.newborns.*.sex' => ['nullable', Rule::in(['M', 'F', 'UNDETERMINED'])],
            'newborn_data.newborns.*.birth_weight_g' => ['nullable', 'integer', 'min:100', 'max:8000'],
            'newborn_data.newborns.*.condition' => ['nullable', 'string', 'max:1000'],
            'newborn_data.newborns.*.apgar' => ['nullable', 'integer', 'min:0', 'max:10'],
            'maternal_care_notes' => ['nullable', 'string', 'max:5000'],
            'baby_care_notes' => [Rule::prohibitedIf(! $canNewborn), 'nullable', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'transmission_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
