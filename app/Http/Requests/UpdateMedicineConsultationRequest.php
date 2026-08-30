<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicineConsultationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $sanitizer = app(ClinicalRichTextSanitizer::class);
        $sanitized = [];

        foreach (['reason', 'clinical_exam'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $sanitized[$field] = $sanitizer->sanitize($this->input($field));
            }
        }

        if ($sanitized !== []) {
            $this->merge($sanitized);
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
            'reason' => [
                'required',
                'string',
                'max:12000',
                function (string $attribute, mixed $value, \Closure $fail) use ($sanitizer): void {
                    $plainText = $sanitizer->plainText(is_string($value) ? $value : '');

                    if ($plainText === '') {
                        $fail('Indiquez le motif de la consultation.');
                    } elseif (mb_strlen($plainText) > 3000) {
                        $fail('Le motif de la consultation ne doit pas dépasser 3 000 caractères.');
                    }
                },
            ],
            'clinical_exam' => [
                'nullable',
                'string',
                'max:40000',
                function (string $attribute, mixed $value, \Closure $fail) use ($sanitizer): void {
                    if (mb_strlen($sanitizer->plainText(is_string($value) ? $value : '')) > 10000) {
                        $fail('Les constatations de l’examen ne doivent pas dépasser 10 000 caractères.');
                    }
                },
            ],
            'decision' => ['nullable', Rule::enum(ConsultationDecision::class)],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif de la consultation.',
            'reason.max' => 'Le contenu du motif de la consultation est trop volumineux.',
            'clinical_exam.max' => 'Le contenu des constatations de l’examen est trop volumineux.',
            'decision.enum' => 'La décision médicale sélectionnée est invalide.',
        ];
    }
}
