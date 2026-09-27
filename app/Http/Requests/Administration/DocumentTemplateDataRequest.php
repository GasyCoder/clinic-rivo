<?php

namespace App\Http\Requests\Administration;

use App\Enums\DocumentDataContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class DocumentTemplateDataRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_type' => mb_strtoupper(trim((string) $this->input('document_type'))),
            'name' => trim((string) $this->input('name')),
        ]);
    }

    public function authorize(): bool
    {
        return $this->route('documentTemplate')
            ? ($this->user()?->can('document_templates.update') ?? false)
            : ($this->user()?->can('document_templates.create') ?? false);
    }

    public function rules(): array
    {
        return [
            // Free label on purpose (ADR-070 §9): CONTRACT/LEAVE/CERTIFICATE/
            // ATTESTATION/LETTER/DECISION/OTHER... or any future category, no
            // code change or referential CRUD screen required.
            'document_type' => ['required', 'string', 'max:80'],
            'data_context' => ['required', new Enum(DocumentDataContext::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'array'],
            // Generous cap: a multi-page canevas can embed a few small
            // inline (base64) images/signatures. If usage grows beyond
            // this, images should move to real asset storage instead of
            // raising this further.
            'content_html' => ['required', 'string', 'max:8000000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'document_type' => 'type de document',
            'data_context' => 'contexte de données',
            'name' => 'nom du canevas',
            'content' => 'contenu',
        ];
    }
}
