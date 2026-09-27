<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase_orders.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_delivery_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Enregistrer et envoyer au fournisseur en une seule opération :
            // la commande n'existe jamais à moitié envoyée.
            'send' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            // ADR-098 — a line names a medicine the clinic already holds, or
            // a line of this supplier's catalogue it takes up now.
            'lines.*.medicine_uuid' => ['nullable', 'required_without:lines.*.supplier_catalog_item_uuid', 'uuid', 'exists:medicines,uuid'],
            'lines.*.supplier_catalog_item_uuid' => ['nullable', 'required_without:lines.*.medicine_uuid', 'uuid', 'exists:supplier_catalog_items,uuid'],
            'lines.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            self::assertDistinctProducts($this->input('lines', []), $validator->errors());
        });
    }

    /**
     * One product, one line — whichever way it was named. Two lines for the
     * same product would be two prices for one thing on the same order.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function assertDistinctProducts(array $lines, MessageBag $errors): void
    {
        $keys = collect($lines)
            ->map(fn (array $line) => filled($line['medicine_uuid'] ?? null)
                ? 'medicine:'.$line['medicine_uuid']
                : 'catalog:'.($line['supplier_catalog_item_uuid'] ?? ''))
            ->filter(fn (string $key) => ! str_ends_with($key, ':'));

        if ($keys->count() !== $keys->unique()->count()) {
            $errors->add('lines', 'Un même médicament ne peut apparaître qu’une seule fois dans la commande.');
        }
    }
}
