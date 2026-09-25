<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Money;

/**
 * ADR-192 — les montants d'une facture une fois sa remise posée, retirée ou
 * recalculée : la remise est recalculée sur la part patient du moment, jamais
 * au-delà ; le reste à payer suit. À appeler sur une facture verrouillée.
 *
 * Une facture ramenée à zéro par sa remise est réglée sans paiement : elle
 * passe « Prise en charge » (`COVERED`, `isSettled()`), comme une couverture à
 * 100 % — aucun paiement ni reçu n'est fabriqué (ADR-047). Si la remise est
 * retirée, elle redevient « À encaisser ».
 */
final class InvoiceDiscountTotals
{
    public static function refresh(Invoice $invoice): Invoice
    {
        $base = InvoiceDiscountResolver::baseMinor($invoice);
        $discount = $invoice->activeDiscount()->first();
        $amount = 0;

        if ($discount !== null) {
            $amount = $discount->discount_type->amountOn($base, (string) $discount->discount_value);
            $discount->forceFill([
                'base_amount' => Money::fromMinor($base),
                'amount' => Money::fromMinor($amount),
            ])->save();
        }

        $total = $base - $amount;
        $invoice->discount_amount = Money::fromMinor($amount);
        $invoice->total_amount = Money::fromMinor($total);
        $invoice->balance_amount = Money::fromMinor($total - Money::toMinor($invoice->paid_amount));

        if ($invoice->status === InvoiceStatus::Validated && $total === 0) {
            $invoice->status = InvoiceStatus::Covered;
        } elseif ($invoice->status === InvoiceStatus::Covered && $total > 0) {
            $invoice->status = InvoiceStatus::Validated;
        }

        $invoice->save();

        return $invoice;
    }
}
