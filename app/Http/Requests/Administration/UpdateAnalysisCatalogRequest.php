<?php

namespace App\Http\Requests\Administration;

use App\Models\AnalysisCatalog;

class UpdateAnalysisCatalogRequest extends StoreAnalysisCatalogRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('analysis_catalog.update');
    }

    public function rules(): array
    {
        $analysis = $this->route('analysisCatalog');

        return $this->catalogRules($analysis instanceof AnalysisCatalog ? $analysis : null);
    }
}
