<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\HospitalStay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-160 — descendre un patient au bloc est une décision médicale : c'est la
 * même autorité que la demander en consultation (`surgery.request`), jamais
 * un droit propre à l'hospitalisation.
 */
class RequestSurgeryFromStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $stay = $this->route('hospitalStay');

        return $stay instanceof HospitalStay
            && $stay->isActive()
            && (bool) $this->user()?->can('surgery.request');
    }

    public function rules(): array
    {
        return [
            // L'intervention est choisie, jamais déduite : aucune demande
            // « sans intervention » n'arrive au bloc (ADR-114).
            'catalog_item_uuid' => [
                'required',
                'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Surgery->value)
                    ->whereNull('deleted_at')),
            ],
            'indication' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'string', 'in:LOW,NORMAL,URGENT'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'catalog_item_uuid.required' => 'Sélectionnez l’intervention envisagée.',
        ];
    }
}
