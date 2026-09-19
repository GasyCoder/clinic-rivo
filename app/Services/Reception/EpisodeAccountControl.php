<?php

namespace App\Services\Reception;

use App\Enums\BillableItemStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\Invoice;
use App\Support\Money;

/**
 * CDC §33.2 — "Contrôle du compte patient":
 *
 *     Total prestations − Paiements reçus − Avoirs / remises autorisés
 *     = Reste à payer
 *
 * Single source of that figure. Réception reads it before deciding an exit
 * and the exit Action recomputes it from the same place: the browser never
 * supplies a balance, exactly as it never supplies a tariff (ADR-028).
 *
 * Discounts and coverage are already deducted inside each Invoice
 * (`total_amount` is net of `discount_amount`/`coverage_amount`, and
 * `balance_amount` is that minus `paid_amount`), so summing the invoices'
 * own balances applies §33.2 without recomputing a second, divergent rule.
 * Cancelled invoices are excluded — a cancelled invoice is not a debt.
 *
 * `pending_amount` is deliberately reported separately rather than folded
 * into the balance: a prestation not yet carried onto a validated invoice
 * is not yet something the patient owes, but letting a passage exit while
 * it sits there would quietly lose real money. Réception is told, and
 * decides.
 */
class EpisodeAccountControl
{
    /**
     * @return array{
     *     invoiced_amount: string, paid_amount: string, balance_amount: string,
     *     pending_amount: string, pending_count: int, is_settled: bool,
     *     invoice_count: int, currency: string
     * }
     */
    public function summarize(Episode $episode): array
    {
        $invoices = Invoice::query()
            ->where('episode_id', $episode->getKey())
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->get(['total_amount', 'paid_amount', 'balance_amount', 'currency']);

        $pending = BillableItem::query()
            ->where('episode_id', $episode->getKey())
            ->where('status', BillableItemStatus::Pending->value)
            ->get(['patient_amount', 'total_amount']);

        $balanceMinor = $invoices->sum(fn (Invoice $invoice): int => Money::toMinor($invoice->balance_amount));
        $pendingMinor = $pending->sum(
            fn (BillableItem $item): int => Money::toMinor($item->patient_amount ?? $item->total_amount),
        );

        return [
            'invoiced_amount' => Money::fromMinor(
                $invoices->sum(fn (Invoice $invoice): int => Money::toMinor($invoice->total_amount)),
            ),
            'paid_amount' => Money::fromMinor(
                $invoices->sum(fn (Invoice $invoice): int => Money::toMinor($invoice->paid_amount)),
            ),
            'balance_amount' => Money::fromMinor($balanceMinor),
            'pending_amount' => Money::fromMinor($pendingMinor),
            'pending_count' => $pending->count(),
            'is_settled' => $balanceMinor === 0,
            'invoice_count' => $invoices->count(),
            // The passage's own currency, read from what was actually
            // invoiced rather than assumed site-wide.
            'currency' => $invoices->first()?->currency ?? 'MGA',
        ];
    }

    /**
     * Same figures for a whole page of the settlement queue, in two
     * queries instead of two per row.
     *
     * @param  array<int, int>  $episodeIds
     * @return array<int, array<string, mixed>> keyed by episode id
     */
    public function summarizeMany(array $episodeIds): array
    {
        if ($episodeIds === []) {
            return [];
        }

        $invoices = Invoice::query()
            ->whereIn('episode_id', $episodeIds)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->get(['episode_id', 'total_amount', 'paid_amount', 'balance_amount', 'currency'])
            ->groupBy('episode_id');

        $pending = BillableItem::query()
            ->whereIn('episode_id', $episodeIds)
            ->where('status', BillableItemStatus::Pending->value)
            ->get(['episode_id', 'patient_amount', 'total_amount'])
            ->groupBy('episode_id');

        $summaries = [];

        foreach ($episodeIds as $episodeId) {
            $episodeInvoices = $invoices->get($episodeId, collect());
            $episodePending = $pending->get($episodeId, collect());
            $balanceMinor = $episodeInvoices->sum(
                fn (Invoice $invoice): int => Money::toMinor($invoice->balance_amount),
            );

            $summaries[$episodeId] = [
                'invoiced_amount' => Money::fromMinor($episodeInvoices->sum(
                    fn (Invoice $invoice): int => Money::toMinor($invoice->total_amount),
                )),
                'paid_amount' => Money::fromMinor($episodeInvoices->sum(
                    fn (Invoice $invoice): int => Money::toMinor($invoice->paid_amount),
                )),
                'balance_amount' => Money::fromMinor($balanceMinor),
                'pending_amount' => Money::fromMinor($episodePending->sum(
                    fn (BillableItem $item): int => Money::toMinor($item->patient_amount ?? $item->total_amount),
                )),
                'pending_count' => $episodePending->count(),
                'is_settled' => $balanceMinor === 0,
                'invoice_count' => $episodeInvoices->count(),
                'currency' => $episodeInvoices->first()?->currency ?? 'MGA',
            ];
        }

        return $summaries;
    }
}
