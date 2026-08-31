<?php

namespace App\Http\Requests\Administration;

use App\Models\HrReferenceValue;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveHrReferenceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        $reference = $this->route('reference');

        return $reference instanceof HrReferenceValue
            && ($this->user()?->can('delete', $reference) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
