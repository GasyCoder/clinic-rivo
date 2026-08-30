<?php

namespace App\Http\Requests;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCareOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('care_orders.create');
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.catalog_item_uuid' => [
                'required',
                'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Care->value)
                    ->where('clinician_orderable', true)
                    ->whereNull('deleted_at')),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'requires_return_to_medicine' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Sélectionnez au moins un acte à demander.',
            'items.min' => 'Sélectionnez au moins un acte à demander.',
            'items.*.catalog_item_uuid.exists' => 'Cet acte n’est plus disponible ou ne peut pas être demandé par un médecin.',
            'items.*.quantity.required' => 'Indiquez la quantité de cet acte.',
            'requires_return_to_medicine.required' => 'Indiquez si le patient doit revenir en Médecine après les soins.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $uuids = collect($this->input('items', []))
                ->pluck('catalog_item_uuid')
                ->filter();

            if ($uuids->count() !== $uuids->unique()->count()) {
                $validator->errors()->add('items', 'Un acte ne peut apparaître qu’une fois dans la même demande.');
            }
        });
    }
}
