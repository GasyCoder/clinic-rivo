<?php

namespace App\Http\Requests\Administration;

use App\Models\EmploymentContract;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveContractRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof EmploymentContract
            && ($this->user()?->can('delete', $contract) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
