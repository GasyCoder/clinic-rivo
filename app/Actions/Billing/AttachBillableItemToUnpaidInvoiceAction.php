<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Appends one Pending BillableItem to an invoice of the same episode — as
 * long as no money has actually changed hands for it yet (DRAFT or
 * VALIDATED with paid_amount still zero). The moment the first payment is
 * recorded (PARTIALLY_PAID/PAID) or the invoice is settled another way
 * (COVERED/CANCELLED), its total is locked for good: a cash movement or a
 * coverage settlement has already been reconciled against that exact
 * amount, so nothing may silently change it afterward (ADR-047). Before
 * that first payment, "validated" only means "ready to be paid", not "shown
 * and fixed" — explicit product decision, not a printed-receipt guarantee.
 */
class AttachBillableItemToUnpaidInvoiceAction
{
    private const ELIGIBLE_STATUSES = [InvoiceStatus::Draft, InvoiceStatus::Validated];

    public function execute(Invoice $invoice, BillableItem $item): ?Invoice
    {
        return DB::transaction(function () use ($invoice, $item) {
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->getKey());
            $item = BillableItem::query()->lockForUpdate()->find($item->getKey());

            if (! $invoice
                || ! in_array($invoice->status, self::ELIGIBLE_STATUSES, true)
                || Money::toMinor($invoice->paid_amount) !== 0
                || ! $item
                || $item->status !== BillableItemStatus::Pending
                || $item->episode_id !== $invoice->episode_id) {
                return null;
            }

            $invoice->lines()->create([
                'billable_item_id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->patient_amount ?? $item->total_amount,
                'gross_line_total' => $item->gross_amount ?? $item->total_amount,
                'staff_coverage_policy' => $item->staff_coverage_policy,
                'coverage_rate' => $item->coverage_rate ?? '0.00',
                'coverage_amount' => $item->coverage_amount ?? '0.00',
                'staff_covered_amount' => $item->staff_covered_amount ?? '0.00',
                'staff_block_credit_used' => $item->staff_block_credit_used ?? '0.00',
                'source_type' => $item->source_type,
                'source_uuid' => $item->source_uuid,
                'status' => 'ACTIVE',
                'created_by' => $item->created_by,
            ]);

            $item->status = BillableItemStatus::Invoiced;
            $item->save();

            // Recomputed from every line's own BillableItem rather than a
            // delta on the invoice's existing totals, so a stray rounding
            // drift can never accumulate across repeated appends.
            $allItems = $invoice->lines()
                ->with('billableItem')
                ->get()
                ->pluck('billableItem')
                ->filter();

            $subtotalMinor = $allItems->sum(fn (BillableItem $i) => Money::toMinor($i->gross_amount ?? $i->total_amount));
            $coverageMinor = $allItems->sum(fn (BillableItem $i) => Money::toMinor($i->coverage_amount ?? '0.00'));
            $patientMinor = $allItems->sum(fn (BillableItem $i) => Money::toMinor($i->patient_amount ?? $i->total_amount));
            $staffCoveredMinor = $allItems->sum(fn (BillableItem $i) => Money::toMinor($i->staff_covered_amount ?? '0.00'));
            $staffBlockCreditUsedMinor = $allItems->sum(fn (BillableItem $i) => Money::toMinor($i->staff_block_credit_used ?? '0.00'));

            if ($subtotalMinor > 999_999_999_999_999
                || $coverageMinor < 0
                || $coverageMinor > $subtotalMinor
                || $patientMinor !== $subtotalMinor - $coverageMinor) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'La répartition entre prise en charge et patient est incohérente.',
                ]);
            }

            $invoice->subtotal_amount = Money::fromMinor($subtotalMinor);
            $invoice->coverage_amount = Money::fromMinor($coverageMinor);
            $invoice->staff_covered_amount = Money::fromMinor($staffCoveredMinor);
            $invoice->staff_block_credit_used = Money::fromMinor($staffBlockCreditUsedMinor);
            $invoice->total_amount = Money::fromMinor($patientMinor);
            $invoice->balance_amount = Money::fromMinor($patientMinor);
            $invoice->save();

            return $invoice->refresh();
        });
    }
}
