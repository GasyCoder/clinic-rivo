<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceReferralRequest extends FormRequest
{
    private const PERMISSION_BY_DESTINATION = [
        'MATERNITY' => 'maternity.request',
        'HOSPITALIZATION' => 'hospitalization.request',
        'TRANSFER' => 'transfer.request',
        'PEDIATRICS' => 'pediatrics.request',
    ];

    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');
        $destination = (string) $this->input('destination');
        $permission = self::PERMISSION_BY_DESTINATION[$destination] ?? null;

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && $permission !== null
            && (bool) $this->user()?->can($permission);
    }

    public function rules(): array
    {
        return [
            'destination' => ['required', Rule::in(array_keys(self::PERMISSION_BY_DESTINATION))],
            'reason' => ['required', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif de cette demande.',
        ];
    }
}
