<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Shared domain entry point for future Medicine/Laboratory/Pharmacy/
 * Surgery/Care modules. Their own authorized Actions call this service;
 * no business module receives a payment capability through it.
 */
class RecordBillableItemAction
{
    /**
     * @param  array{source_module: string, description: string, quantity: int|string, unit_price: int|string, payment_required_before_fulfillment?: bool}  $data
     */
    public function execute(Episode $episode, array $data, User $actor, ?Model $source = null): BillableItem
    {
        $module = strtoupper(trim($data['source_module'] ?? ''));
        $description = trim($data['description'] ?? '');

        if ($module === '' || mb_strlen($module) > 50) {
            throw ValidationException::withMessages([
                'source_module' => 'Le module source de la prestation est obligatoire.',
            ]);
        }

        if ($description === '' || mb_strlen($description) > 255) {
            throw ValidationException::withMessages([
                'description' => 'La description de la prestation est obligatoire.',
            ]);
        }

        $quantityMinor = Money::toMinor($data['quantity']);
        $unitPriceMinor = Money::toMinor($data['unit_price']);
        $totalMinor = Money::multiply($data['quantity'], $data['unit_price']);

        if ($quantityMinor <= 0 || $unitPriceMinor <= 0 || $totalMinor > 999_999_999_999_999) {
            throw ValidationException::withMessages([
                'amount' => 'La quantité et le tarif doivent produire un montant valide supérieur à zéro.',
            ]);
        }

        return BillableItem::create([
            'episode_id' => $episode->id,
            'source_module' => $module,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'source_uuid' => $source?->getAttribute('uuid'),
            'description' => $description,
            'quantity' => Money::normalize($data['quantity']),
            'unit_price' => Money::fromMinor($unitPriceMinor),
            'total_amount' => Money::fromMinor($totalMinor),
            'currency' => 'MGA',
            'payment_required_before_fulfillment' => (bool) ($data['payment_required_before_fulfillment'] ?? false),
            'status' => BillableItemStatus::Pending,
            'created_by' => $actor->id,
        ]);
    }
}
