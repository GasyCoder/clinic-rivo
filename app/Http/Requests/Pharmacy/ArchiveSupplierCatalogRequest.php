<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveSupplierCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supplier_catalogs.delete') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
