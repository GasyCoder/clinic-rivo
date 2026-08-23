<?php

namespace App\Http\Requests;

use App\Models\ProfessionalProfile;
use App\Support\AnesthesiaAssessmentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAnesthesiaRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'anesthetist_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deactivated_at')
                    ->whereIn('professional_profile_id', ProfessionalProfile::query()
                        ->select('id')
                        ->where('code', 'ANESTHETIST')
                        ->where('active', true))),
            ],
            'notes' => ['nullable', 'string'],
            'administered_at' => ['nullable', 'date'],
            ...AnesthesiaAssessmentRules::consultation(),
            ...AnesthesiaAssessmentRules::paraclinical(),
            ...AnesthesiaAssessmentRules::peroperative(),
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $systolic = $this->input('consultation_data.blood_pressure_systolic');
            $diastolic = $this->input('consultation_data.blood_pressure_diastolic');
            $hasSystolic = $systolic !== null && $systolic !== '';
            $hasDiastolic = $diastolic !== null && $diastolic !== '';

            if ($hasSystolic !== $hasDiastolic) {
                $validator->errors()->add('consultation_data.blood_pressure_systolic', 'Renseignez les deux valeurs de la tension artérielle.');
            } elseif ($hasSystolic && is_numeric($systolic) && is_numeric($diastolic) && (float) $diastolic >= (float) $systolic) {
                $validator->errors()->add('consultation_data.blood_pressure_diastolic', 'La tension diastolique doit être inférieure à la tension systolique.');
            }

            foreach ((array) $this->input('anesthetic_items', []) as $index => $item) {
                if (($item['reference_code'] ?? null) === 'ANESTH-OTHER' && blank($item['details'] ?? null)) {
                    $validator->errors()->add("anesthetic_items.{$index}.details", 'Précisez l’élément d’anesthésie « Autres ».');
                }
            }
        }];
    }
}
