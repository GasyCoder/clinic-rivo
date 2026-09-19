<?php

namespace App\Http\Requests;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/** ADR-108 — une nouvelle feuille de compte rendu, écrite par un médecin. */
class StoreImagingReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('imaging_templates.create');
    }

    protected function prepareForValidation(): void
    {
        // La longueur se juge sur le HTML réellement conservé.
        $this->merge([
            'body_html' => app(ClinicalRichTextSanitizer::class)->sanitize((string) $this->input('body_html')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:10000'],
            // L'examen d'où la feuille est écrite, si elle doit y être proposée d'office.
            'default_for_item_uuid' => ['nullable', 'uuid', 'exists:imaging_request_items,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Donnez un nom à la feuille.',
            'body_html.required' => 'La feuille est vide : écrivez d’abord ses rubriques.',
            'body_html.max' => 'La feuille est trop longue : 10 000 caractères de mise en forme comprise.',
        ];
    }
}
