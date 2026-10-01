<?php

namespace App\Http\Requests\Administration;

use App\Models\Bank;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveBankRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        $bank = $this->route('bank');

        return $bank instanceof Bank && ($this->user()?->can('delete', $bank) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
