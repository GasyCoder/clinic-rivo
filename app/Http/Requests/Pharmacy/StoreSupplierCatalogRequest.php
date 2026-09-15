<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supplier_catalogs.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,pdf', 'max:10240'],
            'catalog_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Sélectionnez un fichier de catalogue.',
            'file.mimes' => 'Seuls les fichiers Excel (.xlsx, .xls) ou PDF sont acceptés.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
        ];
    }
}
