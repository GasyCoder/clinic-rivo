<?php

namespace App\Http\Requests;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSurgicalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'episode_uuid' => ['required', 'string', 'exists:episodes,uuid'],
            'catalog_item_uuid' => [
                'required',
                'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Surgery->value)
                    ->where('code', 'like', 'SURG-%')),
            ],
            'procedure_details' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('catalog_item_uuid')) {
                return;
            }

            $isOther = CatalogItem::query()
                ->where('uuid', $this->input('catalog_item_uuid'))
                ->where('code', 'SURG-OTHER')
                ->exists();

            if ($isOther && blank($this->input('procedure_details'))) {
                $validator->errors()->add('procedure_details', 'Précisez l’intervention chirurgicale choisie dans « Autres ».');
            }
        }];
    }
}
