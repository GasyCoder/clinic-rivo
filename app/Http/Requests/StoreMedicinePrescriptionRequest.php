<?php

namespace App\Http\Requests;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMedicinePrescriptionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->map(fn ($line) => is_array($line) ? [...$line, 'manual' => (bool) ($line['manual'] ?? false)] : $line)
            ->all();

        $this->merge(['lines' => $lines]);
    }

    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('prescriptions.create')
            && (bool) $this->user()?->can('medicines.view')
            && (bool) $this->user()?->can('stock.availability.view');
    }

    public function rules(): array
    {
        return [
            'continue_to_decision' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.manual' => ['sometimes', 'boolean'],
            'lines.*.medicine_uuid' => [
                'nullable',
                'required_if:lines.*.manual,false',
                'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Medicine->value)
                    ->where('module', CatalogModule::Pharmacy->value)
                    ->where('stockable', true)
                    ->whereNull('deleted_at')),
            ],
            'lines.*.medication_name' => ['nullable', 'required_if:lines.*.manual,true', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.dosage' => ['required', 'string', 'max:255'],
            'lines.*.frequency' => ['required', 'string', 'max:255'],
            'lines.*.duration' => ['nullable', 'string', 'max:255'],
            'lines.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Ajoutez au moins une ligne à l’ordonnance.',
            'lines.min' => 'Ajoutez au moins une ligne à l’ordonnance.',
            'lines.*.medicine_uuid.required_if' => 'Sélectionnez un médicament du référentiel Pharmacie.',
            'lines.*.medicine_uuid.exists' => 'Ce médicament n’est plus disponible dans le référentiel Pharmacie.',
            'lines.*.medication_name.required_if' => 'Indiquez le nom du médicament ajouté manuellement.',
            'lines.*.quantity.required' => 'Indiquez la quantité à réserver.',
            'lines.*.quantity.min' => 'La quantité doit être au moins égale à 1.',
            'lines.*.dosage.required' => 'Indiquez la dose (mode d’emploi) de ce médicament.',
            'lines.*.frequency.required' => 'Indiquez la fréquence de prise de ce médicament.',
        ];
    }

    /**
     * `distinct` is not used on `medicine_uuid` above because several manual
     * lines legitimately share a null value there — this replaces it with a
     * check scoped to the catalog-linked lines only.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $uuids = collect($this->input('lines', []))
                ->filter(fn ($line) => is_array($line) && ! ($line['manual'] ?? false))
                ->pluck('medicine_uuid')
                ->filter();

            if ($uuids->count() !== $uuids->unique()->count()) {
                $validator->errors()->add('lines', 'Un médicament du référentiel ne peut apparaître qu’une fois dans la même ordonnance.');
            }
        });
    }
}
