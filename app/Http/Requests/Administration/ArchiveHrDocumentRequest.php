<?php

namespace App\Http\Requests\Administration;

use App\Models\HrDocument;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveHrDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof HrDocument
            && ($this->user()?->can('delete', $document) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
