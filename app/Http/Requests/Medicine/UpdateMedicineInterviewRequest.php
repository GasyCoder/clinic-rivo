<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ConsultationEvolution;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\PatientAntecedentType;
use App\Models\EpisodeOrientation;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicineInterviewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['reason'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([
                    $field => app(ClinicalRichTextSanitizer::class)->sanitize($this->input($field)),
                ]);
            }
        }
    }

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
        $sanitizer = app(ClinicalRichTextSanitizer::class);
        $completing = $this->boolean('complete', true);

        return [
            'chief_complaint' => [
                Rule::requiredIf($completing),
                'nullable',
                'string',
                'max:255',
            ],
            'reason' => [
                Rule::requiredIf($completing),
                'nullable',
                'string',
                'max:12000',
                function (string $attribute, mixed $value, \Closure $fail) use ($sanitizer, $completing): void {
                    $plainText = $sanitizer->plainText(is_string($value) ? $value : '');

                    if ($completing && $plainText === '') {
                        $fail('Renseignez l’histoire clinique avant de poursuivre.');
                    } elseif (mb_strlen($plainText) > 3000) {
                        $fail('L’histoire clinique ne doit pas dépasser 3 000 caractères.');
                    }
                },
            ],
            'symptom_onset' => ['nullable', 'string', 'max:150'],
            'evolution' => ['nullable', Rule::enum(ConsultationEvolution::class)],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
            'known_treatment_change' => ['nullable', Rule::in(['YES', 'NO'])],
            'known_treatment_change_notes' => [
                Rule::requiredIf($this->input('known_treatment_change') === 'YES'),
                'nullable',
                'string',
                'max:2000',
            ],
            // false = « Enregistrer » (brouillon, on reste sur l'étape) ;
            // true/absent = « Enregistrer et continuer » (l'étape est
            // validée). Jamais déduit de la présence de contenu.
            'complete' => ['sometimes', 'boolean'],
            'current_treatments' => ['sometimes', 'array', 'max:20'],
            'current_treatments.*.medication_name' => ['required', 'string', 'max:255'],
            'current_treatments.*.dosage' => ['nullable', 'string', 'max:150'],
            'current_treatments.*.frequency' => ['nullable', 'string', 'max:150'],
            'current_treatments.*.duration' => ['nullable', 'string', 'max:150'],
            'current_treatments.*.notes' => ['nullable', 'string', 'max:500'],
            'reported_allergies' => ['sometimes', 'array', 'max:10'],
            'reported_allergies.*.substance' => ['required', 'string', 'max:255'],
            'reported_allergies.*.reaction' => ['nullable', 'string', 'max:500'],
            'reported_allergies.*.promote_to_patient_record' => ['sometimes', 'boolean'],
            'reported_antecedents' => ['sometimes', 'array', 'max:10'],
            'reported_antecedents.*.description' => ['required', 'string', 'max:2000'],
            'reported_antecedents.*.type' => ['required', Rule::enum(PatientAntecedentType::class)],
            'reported_antecedents.*.promote_to_patient_record' => ['sometimes', 'boolean'],
            'reported_habitual_treatments' => ['sometimes', 'array', 'max:10'],
            'reported_habitual_treatments.*.medication_name' => ['required', 'string', 'max:255'],
            'reported_habitual_treatments.*.dosage' => ['nullable', 'string', 'max:150'],
            'reported_habitual_treatments.*.frequency' => ['nullable', 'string', 'max:150'],
            'reported_habitual_treatments.*.duration' => ['nullable', 'string', 'max:150'],
            'reported_habitual_treatments.*.notes' => ['nullable', 'string', 'max:500'],
            'reported_habitual_treatments.*.promote_to_patient_record' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'chief_complaint.required' => 'Veuillez renseigner le motif principal.',
            'reason.required' => 'Renseignez l’histoire clinique avant de poursuivre.',
            'known_treatment_change_notes.required' => 'Décrivez le changement signalé par le patient.',
            'current_treatments.*.medication_name.required' => 'Indiquez le nom du traitement en cours.',
            'reported_allergies.*.substance.required' => 'Indiquez la substance concernée.',
            'reported_antecedents.*.description.required' => 'Décrivez le nouvel antécédent.',
            'reported_habitual_treatments.*.medication_name.required' => 'Indiquez le nom du traitement habituel.',
        ];
    }
}
