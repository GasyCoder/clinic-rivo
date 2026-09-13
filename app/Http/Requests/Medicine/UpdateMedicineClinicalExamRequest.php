<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ClinicalExamSystem;
use App\Enums\ClinicalSystemStatus;
use App\Enums\ConsciousnessStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\GeneralCondition;
use App\Models\EpisodeOrientation;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicineClinicalExamRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('clinical_exam') && is_string($this->input('clinical_exam'))) {
            $this->merge([
                'clinical_exam' => app(ClinicalRichTextSanitizer::class)->sanitize($this->input('clinical_exam')),
            ]);
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

        return [
            'complete' => ['sometimes', 'boolean'],

            // The examination is no longer one block of prose, so the prose is
            // no longer required: a doctor who recorded the general condition
            // and the systems has documented the examination. It stays capped
            // because it is still stored as sanitised HTML.
            'clinical_exam' => [
                'nullable',
                'string',
                'max:40000',
                function (string $attribute, mixed $value, Closure $fail) use ($sanitizer): void {
                    $plainText = $sanitizer->plainText(is_string($value) ? $value : '');

                    if (mb_strlen($plainText) > 10000) {
                        $fail('Les notes cliniques ne doivent pas dépasser 10 000 caractères.');
                    }
                },
            ],

            // Never required and never defaulted: an unset general condition
            // is an unmade assessment, not a good one.
            'general_condition' => ['nullable', Rule::in(GeneralCondition::values())],
            'consciousness_status' => ['nullable', Rule::in(ConsciousnessStatus::values())],
            'consciousness_details' => [
                'nullable', 'string', 'max:500',
                Rule::requiredIf(fn (): bool => $this->input('consciousness_status') === ConsciousnessStatus::Other->value),
            ],
            'general_observation' => ['nullable', 'string', 'max:2000'],

            // « Le diagnostic peut-il être posé maintenant ? » — même
            // tri-état : null tant que le médecin n'a pas répondu.
            'diagnosis_ready' => ['nullable', 'boolean'],

            'systems' => ['sometimes', 'array', 'max:'.count(ClinicalExamSystem::cases())],
            'systems.*.system_code' => ['required', Rule::in(ClinicalExamSystem::values())],
            'systems.*.status' => ['required', Rule::in(ClinicalSystemStatus::values())],
            'systems.*.findings' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $systems = $this->input('systems', []);

            if (! is_array($systems)) {
                return;
            }

            $seen = [];

            foreach ($systems as $index => $entry) {
                $code = is_array($entry) ? ($entry['system_code'] ?? null) : null;
                $status = is_array($entry) ? ($entry['status'] ?? null) : null;

                // An anomaly whose findings are empty records that something
                // is wrong without saying what — unusable to whoever reads
                // the file next. Only ABNORMAL demands the text; NORMAL and
                // NOT_EXAMINED never do.
                if ($status === ClinicalSystemStatus::Abnormal->value
                    && trim((string) (is_array($entry) ? ($entry['findings'] ?? '') : '')) === '') {
                    $validator->errors()->add(
                        "systems.{$index}.findings",
                        'Veuillez renseigner les constatations de l’anomalie.',
                    );
                }

                // One statement per system. Two rows for the same system would
                // let the last one silently win.
                if ($code !== null && in_array($code, $seen, true)) {
                    $validator->errors()->add(
                        "systems.{$index}.system_code",
                        'Cet appareil est renseigné deux fois.',
                    );
                }

                $seen[] = $code;
            }
        });
    }

    public function messages(): array
    {
        return [
            'consciousness_details.required' => 'Précisez l’état de conscience observé.',
            'general_condition.in' => 'Cet état général n’existe pas.',
            'systems.*.status.in' => 'Ce statut d’examen n’existe pas.',
            'systems.*.system_code.in' => 'Cet appareil n’existe pas dans la grille d’examen.',
        ];
    }
}
