<?php

namespace App\Http\Requests;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/** ADR-108 — renommer une feuille ajoutée, et éventuellement remplacer son contenu. */
class UpdateImagingReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('imaging_templates.update');
    }

    protected function prepareForValidation(): void
    {
        // Absent, le contenu de la feuille ne change pas : on ne fabrique pas
        // une chaîne vide qui le remplacerait par rien.
        if ($this->has('body_html') && $this->input('body_html') !== null) {
            $this->merge([
                'body_html' => app(ClinicalRichTextSanitizer::class)->sanitize((string) $this->input('body_html')),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Donnez un nom à la feuille.',
            'body_html.max' => 'La feuille est trop longue : 10 000 caractères de mise en forme comprise.',
        ];
    }
}
