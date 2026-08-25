<?php

namespace App\Services\Finance;

use App\Enums\BillableItemStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Support\Money;

/**
 * Read-only hook for Laboratory and Pharmacy. It never creates a payment.
 * Items that require prior payment are cleared only when the invoice that
 * contains them is fully paid; partial allocation per invoice line is not
 * defined by the CDC and is deliberately not inferred here.
 */
class BillableItemFinancialClearance
{
    public function isSatisfied(BillableItem $item): bool
    {
        if (! $item->payment_required_before_fulfillment) {
            return true;
        }

        if ($item->status !== BillableItemStatus::Invoiced) {
            return false;
        }

        $invoice = $item->invoiceLine?->invoice;

        return in_array($invoice?->status, [InvoiceStatus::Paid, InvoiceStatus::Covered], true)
            && Money::toMinor($invoice->balance_amount) === 0;
    }
}
