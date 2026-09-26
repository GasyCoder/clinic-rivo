<?php

namespace App\Http\Requests\Administration;

use App\Models\GeneratedDocument;
use Illuminate\Foundation\Http\FormRequest;

/** ADR-199 — archiver un document généré : un motif, jamais d'effacement. */
class ArchiveGeneratedDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        $document = $this->route('generatedDocument');

        return $document instanceof GeneratedDocument
            && ($this->user()?->can('delete', $document) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
