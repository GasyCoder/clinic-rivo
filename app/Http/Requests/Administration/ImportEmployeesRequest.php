<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.import') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => 'fichier d’import'];
    }
}
