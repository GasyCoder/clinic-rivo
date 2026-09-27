<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveDocumentTemplateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('document_templates.archive') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
