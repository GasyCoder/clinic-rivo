<?php

namespace App\Http\Requests\Pharmacy;

use App\Enums\PharmacyStockEntryOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stock.entry') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'medicine_uuid' => ['required', 'uuid', 'exists:medicines,uuid'],
            'operation' => ['required', Rule::enum(PharmacyStockEntryOperation::class)],
            'lot_number' => ['required', 'string', 'max:100'],
            'received_at' => ['nullable', 'date'],
            'expires_at' => [
                'required',
                'date',
                Rule::when($this->filled('received_at'), ['after_or_equal:received_at']),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'supplier_uuid' => ['nullable', 'uuid', 'exists:medicine_suppliers,uuid'],
            'unit_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'origin' => ['required', 'string', 'max:150'],
            'destination' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    protected function passedValidation(): void
    {
        if ($this->filled('unit_purchase_price') && ! $this->user()?->can('stock.cost.record')) {
            abort(403, 'Vous ne pouvez pas enregistrer un prix d’achat.');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'medicine_uuid.required' => 'Sélectionnez un médicament.',
            'medicine_uuid.exists' => 'Le médicament sélectionné n’existe plus.',
            'operation.required' => 'Sélectionnez le type d’entrée.',
            'operation.enum' => 'Le type d’entrée est invalide.',
            'lot_number.required' => 'Le numéro de lot est obligatoire.',
            'expires_at.required' => 'La date de péremption est obligatoire.',
            'expires_at.after_or_equal' => 'La péremption ne peut pas précéder la réception.',
            'quantity.required' => 'La quantité est obligatoire.',
            'quantity.min' => 'La quantité doit être supérieure à zéro.',
            'origin.required' => 'L’origine du mouvement est obligatoire.',
            'destination.required' => 'La destination du mouvement est obligatoire.',
            'reason.required' => 'Le motif de l’entrée est obligatoire.',
            'reason.min' => 'Le motif doit comporter au moins 3 caractères.',
        ];
    }
}
