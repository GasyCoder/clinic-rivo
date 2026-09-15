<?php

namespace App\Http\Requests\Pharmacy;

use App\Enums\PharmacyStockEntryOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ADR-098 — one delivery (supplier, date, origin, destination, reason) and
 * the list of medicines it brought. Each line obeys the rules of a single
 * stock entry; the whole list is recorded at once or not at all.
 */
class StoreStockEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stock.entry') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'received_at' => ['nullable', 'date'],
            'supplier_uuid' => ['nullable', 'uuid', 'exists:medicine_suppliers,uuid'],
            'origin' => ['required', 'string', 'max:150'],
            'destination' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.medicine_uuid' => ['required', 'uuid', 'exists:medicines,uuid'],
            'entries.*.operation' => ['required', Rule::enum(PharmacyStockEntryOperation::class)],
            'entries.*.lot_number' => ['required', 'string', 'max:100'],
            'entries.*.expires_at' => [
                'required',
                'date',
                Rule::when($this->filled('received_at'), ['after_or_equal:received_at']),
            ],
            'entries.*.quantity' => ['required', 'integer', 'min:1'],
            'entries.*.unit_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seen = [];

            foreach ($this->input('entries', []) as $index => $entry) {
                $key = mb_strtolower(($entry['medicine_uuid'] ?? '').'|'.trim((string) ($entry['lot_number'] ?? '')));

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "entries.{$index}.lot_number",
                        sprintf('Ligne %d : ce lot figure déjà à la ligne %d. Modifiez cette ligne plutôt que de l’ajouter deux fois.', $index + 1, $seen[$key] + 1),
                    );
                }

                $seen[$key] ??= $index;
            }
        });
    }

    protected function passedValidation(): void
    {
        $priced = collect($this->input('entries', []))->contains(fn ($entry) => filled($entry['unit_purchase_price'] ?? null));

        if ($priced && ! $this->user()?->can('stock.cost.record')) {
            abort(403, 'Vous ne pouvez pas enregistrer un prix d’achat.');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'entries.required' => 'Ajoutez au moins un médicament à la liste.',
            'entries.*.medicine_uuid.required' => 'Sélectionnez un médicament.',
            'entries.*.lot_number.required' => 'Le numéro de lot est obligatoire.',
            'entries.*.expires_at.required' => 'La date de péremption est obligatoire.',
            'entries.*.expires_at.after_or_equal' => 'La péremption ne peut pas précéder la réception.',
            'entries.*.quantity.min' => 'La quantité doit être supérieure à zéro.',
            'origin.required' => 'La provenance est obligatoire.',
            'destination.required' => 'Le lieu de rangement est obligatoire.',
            'reason.required' => 'Le motif de l’entrée est obligatoire.',
            'reason.min' => 'Le motif doit comporter au moins 3 caractères.',
        ];
    }
}
