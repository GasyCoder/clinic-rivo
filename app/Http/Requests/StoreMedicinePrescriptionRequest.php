<?php

namespace App\Http\Requests;

use App\Enums\AdministrationRoute;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ClinicalSuggestionSource;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicineForm;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
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
            // ADR-110 — la dose n'est exigée que pour un produit qui se
            // dose. Une compresse stérile ou une paire de gants n'a pas de
            // dose : on en utilise un nombre, et réclamer « 500 mg » sur un
            // paquet de dix créait un champ obligatoire impossible à remplir
            // honnêtement. La forme du référentiel décide (ADR-036), jamais
            // un libellé ni un code (ADR-052).
            // `forEach` n'est jamais enveloppée dans un tableau : Laravel ne
            // la reconnaît alors plus comme règle compilable, l'attribut
            // résolu est perdu et toutes les lignes retombent sur
            // « required » — le défaut que le test de cette ADR a attrapé.
            'lines.*.dosage' => Rule::forEach(
                fn ($value, string $attribute): array => $this->dosageRulesFor($attribute),
            ),
            // Clinically decisive: 500 mg orally is not 500 mg IV.
            'lines.*.route' => ['nullable', Rule::in(AdministrationRoute::values())],
            'lines.*.frequency' => ['required', 'string', 'max:255'],
            'lines.*.duration' => ['nullable', 'string', 'max:255'],
            'lines.*.instructions' => ['nullable', 'string', 'max:1000'],
            // ADR-111 — le protocole qui a proposé la ligne. Le médecin a pu
            // en changer la dose ou la durée : la trace dit d'où venait la
            // proposition, pas qu'elle a été reprise telle quelle.
            'lines.*.suggestion_protocol_uuid' => ['nullable', 'uuid'],
            'lines.*.suggestion_source' => ['nullable', Rule::enum(ClinicalSuggestionSource::class)],
        ];
    }

    /**
     * Les règles de la dose d'une ligne donnée.
     *
     * `forEach` donne l'attribut résolu (`lines.3.dosage`) : on peut donc
     * remonter à la ligne et lire la forme de son médicament, ce qu'une
     * règle `lines.*` ne permet pas.
     *
     * @return array<int, mixed>
     */
    private function dosageRulesFor(string $attribute): array
    {
        $index = explode('.', $attribute)[1] ?? null;
        $line = $index === null ? [] : (array) $this->input("lines.{$index}", []);
        $uuid = $line['medicine_uuid'] ?? null;

        $undosed = $uuid !== null && Medicine::query()
            ->whereIn('form', MedicineForm::undosedValues())
            ->whereHas('catalogItem', fn ($query) => $query->where('uuid', $uuid))
            ->exists();

        return [$undosed ? 'nullable' : 'required', 'string', 'max:255'];
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
