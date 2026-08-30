<?php

namespace App\Http\Requests;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurgicalReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('surgery.request');
    }

    public function rules(): array
    {
        return [
            'catalog_item_uuid' => [
                'required',
                'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Surgery->value)
                    ->whereNull('deleted_at')),
            ],
            'diagnostic' => ['required', 'string', 'max:2000'],
            'indication' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'string', 'in:LOW,NORMAL,URGENT'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'diagnostic.required' => 'Indiquez le diagnostic motivant la demande.',
            'catalog_item_uuid.required' => 'Sélectionnez l’intervention envisagée.',
        ];
    }
}
