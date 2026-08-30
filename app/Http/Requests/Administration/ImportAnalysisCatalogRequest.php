<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class ImportAnalysisCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('analysis_catalog.import');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ];
    }
}
