<?php

namespace App\Http\Requests\Administration;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveEmployeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->exists('reason') && is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && ($this->user()?->can('delete', $employee) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    public function attributes(): array
    {
        return ['reason' => 'motif d’archivage'];
    }
}
