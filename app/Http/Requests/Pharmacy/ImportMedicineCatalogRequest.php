<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class ImportMedicineCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medicines.import') === true
            && $this->user()?->can('medicines.create') === true
            && $this->user()?->can('catalog.items.create') === true
            && $this->user()?->can('catalog.tariffs.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
        ];
    }
}
