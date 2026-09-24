<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-182 — ce qui entre au stock vient d'une livraison réceptionnée, et
 * de rien d'autre.
 *
 * La réception a constaté ce qui est arrivé ; ici le pharmacien le relit,
 * corrige ce qu'il a sous les yeux, et le range. Aucune entrée libre : un
 * don, un stock de départ ou le dépannage d'un confrère n'ont plus de chemin
 * local — c'est la décision du propriétaire du 2026-09-23, qui revient sur
 * l'ADR-179 §7 et l'ADR-180.
 *
 * Les champs de l'ancienne entrée libre sont explicitement refusés plutôt
 * qu'ignorés : un envoi forgé reçoit une erreur nommée, et l'interface n'est
 * jamais la seule protection. Il en va de même du prix d'achat, qui vient de
 * la réception et ne se saisit jamais en rangeant (ADR-174).
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
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.uuid' => ['required', 'uuid', 'distinct', 'exists:goods_receipt_lines,uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.lot_number' => ['required', 'string', 'max:100'],
            'lines.*.expires_at' => ['required', 'date', 'after_or_equal:today'],
            // ADR-174 — le prix de vente peut se fixer à l'entrée, par qui en a le droit.
            'lines.*.sale_price' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'lines.*.sale_name' => ['nullable', 'string', 'max:255'],
            // ADR-174 — le prix d'achat est celui de la réception.
            'lines.*.unit_purchase_price' => ['prohibited'],

            // Ce qui appartenait à l'entrée sans commande.
            'entries' => ['prohibited'],
            'origin' => ['prohibited'],
            'supplier_uuid' => ['prohibited'],
            'destination' => ['prohibited'],
            'reason' => ['prohibited'],
            'received_at' => ['prohibited'],
        ];
    }

    protected function passedValidation(): void
    {
        $lines = collect($this->input('lines', []));

        if ($lines->contains(fn (array $line) => filled($line['sale_price'] ?? null)) && ! $this->user()?->can('medicines.sale_price.update')) {
            abort(403, 'Vous ne pouvez pas fixer un prix de vente.');
        }

        if ($lines->contains(fn (array $line) => filled($line['sale_name'] ?? null)) && ! $this->user()?->can('medicines.name.update')) {
            abort(403, 'Vous ne pouvez pas renommer un médicament.');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Cochez au moins une ligne à faire entrer en stock.',
            'lines.*.uuid.exists' => 'Cette ligne de réception n’existe plus.',
            'lines.*.uuid.distinct' => 'Cette ligne de réception est envoyée deux fois.',
            'lines.*.quantity.min' => 'La quantité doit être d’au moins 1.',
            'lines.*.lot_number.required' => 'Le numéro de lot est obligatoire.',
            'lines.*.expires_at.required' => 'La date de péremption est obligatoire.',
            'lines.*.expires_at.after_or_equal' => 'La péremption ne peut pas précéder l’entrée en stock.',
            'lines.*.unit_purchase_price.prohibited' => 'Le prix d’achat vient de la réception : il ne se saisit pas en rangeant.',
            'entries.prohibited' => 'Le stock n’entre que depuis une livraison réceptionnée : réceptionnez d’abord la commande.',
            'origin.prohibited' => 'La provenance vient de la réception : elle ne se saisit plus.',
            'supplier_uuid.prohibited' => 'Le fournisseur vient de la commande réceptionnée.',
            'destination.prohibited' => 'Le rangement est le stock de la pharmacie : il ne se saisit plus.',
            'reason.prohibited' => 'Le motif vient de la réception : il ne se saisit plus.',
            'received_at.prohibited' => 'La date d’entrée est posée par le serveur.',
        ];
    }
}
