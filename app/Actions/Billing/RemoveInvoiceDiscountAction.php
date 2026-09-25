<?php

namespace App\Actions\Billing;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Billing\InvoiceDiscountTotals;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-192 — retirer la remise d'une facture qui n'a encore rien reçu. Elle ne
 * s'efface pas : elle reste, datée et signée du retrait ; un coupon retrouve
 * l'usage qu'il avait consommé.
 */
class RemoveInvoiceDiscountAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(Invoice $invoice, User $actor, ?string $reason = null): Invoice
    {
        if ($actor->cannot('discounts.create')) {
            throw new AuthorizationException('Vous ne pouvez pas retirer de remise.');
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            ApplyInvoiceDiscountAction::ensureDiscountable($invoice, removing: true);

            $discount = $invoice->activeDiscount()->lockForUpdate()->first();

            if ($discount === null) {
                throw ValidationException::withMessages(['discount' => 'Cette facture n’a aucune remise à retirer.']);
            }

            $before = ApplyInvoiceDiscountAction::totals($invoice);

            $discount->forceFill([
                'removed_at' => now(),
                'removed_by' => $actor->id,
                'remove_reason' => filled($reason) ? trim($reason) : null,
                'active_key' => null,
            ])->save();

            if ($discount->discount_coupon_id !== null) {
                $discount->coupon()->where('uses_count', '>', 0)->decrement('uses_count');
            }

            InvoiceDiscountTotals::refresh($invoice);

            $this->auditor->record(
                'billing.discount.remove',
                entity: $invoice,
                oldValues: [...$before, 'discount' => $discount->label],
                newValues: ApplyInvoiceDiscountAction::totals($invoice),
                module: 'billing',
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }
}
