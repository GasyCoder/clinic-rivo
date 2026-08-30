<?php

namespace App\Http\Requests;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImagingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('imaging_orders.create');
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.catalog_item_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Imaging->value)
                    ->whereNull('deleted_at')),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'continue_to_diagnosis' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Sélectionnez au moins un examen.',
            'items.min' => 'Sélectionnez au moins un examen.',
        ];
    }
}
