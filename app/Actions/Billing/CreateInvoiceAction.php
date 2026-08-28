<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Services\Finance\FinancialNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInvoiceAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly RecordBillableItemAction $recordBillableItem,
    ) {}

    /**
     * @param  array{episode_uuid: string, billable_item_uuids?: array<int, string>, catalog_lines?: array<int, array{catalog_item_uuid: string, quantity: int|string}>}  $data
     */
    public function execute(Patient $patient, array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($patient, $data, $actor) {
            $episode = $patient->episodes()
                ->where('uuid', $data['episode_uuid'])
                ->lockForUpdate()
                ->first();

            if (! $episode) {
                throw ValidationException::withMessages([
                    'episode_uuid' => 'Le passage sélectionné n’appartient pas à ce patient.',
                ]);
            }

            // STAFF is a decision of this Episode, not a permanent Patient
            // category. The future RH / Finance credit rules must calculate
            // the share before this normal invoice path can be used.
            if ($episode->financial_mode === EpisodeFinancialMode::Staff) {
                throw ValidationException::withMessages([
                    'financial_mode' => 'La couverture Personnel doit être calculée par RH / Finance avant toute facturation.',
                ]);
            }

            $items = $this->selectedItems($episode->id, $data['billable_item_uuids'] ?? []);

            foreach ($data['catalog_lines'] ?? [] as $line) {
                $items->push($this->recordBillableItem->execute($episode, [
                    'catalog_item_uuid' => $line['catalog_item_uuid'],
                    'quantity' => $line['quantity'],
                    'payment_required_before_fulfillment' => false,
                ], $actor));
            }

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'Ajoutez ou sélectionnez au moins une prestation facturable.',
                ]);
            }

            $subtotalMinor = $items->sum(fn (BillableItem $item) => Money::toMinor(
                $item->gross_amount ?? $item->total_amount,
            ));
            $coverageMinor = $items->sum(fn (BillableItem $item) => Money::toMinor(
                $item->coverage_amount ?? '0.00',
            ));
            $patientMinor = $items->sum(fn (BillableItem $item) => Money::toMinor(
                $item->patient_amount ?? $item->total_amount,
            ));

            if ($subtotalMinor <= 0 || $subtotalMinor > 999_999_999_999_999) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'Le montant total de la facture est invalide ou dépasse la limite autorisée.',
                ]);
            }

            if ($coverageMinor < 0 || $coverageMinor > $subtotalMinor || $patientMinor !== $subtotalMinor - $coverageMinor) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'La répartition entre mutuelle et patient est incohérente.',
                ]);
            }

            $subtotal = Money::fromMinor($subtotalMinor);
            $patientTotal = Money::fromMinor($patientMinor);
            $organizationUuids = $items->pluck('mutual_organization_uuid')->filter()->unique();
            $organizationNames = $items->pluck('mutual_organization_name')->filter()->unique();
            $coverageRates = $items->pluck('coverage_rate')->filter(fn ($rate) => $rate !== null)->unique();
            $invoice = Invoice::create([
                'patient_id' => $patient->id,
                'episode_id' => $episode->id,
                'invoice_number' => $this->numbers->invoice(),
                'status' => InvoiceStatus::Draft,
                'currency' => 'MGA',
                'mutual_organization_uuid' => $organizationUuids->count() === 1 ? $organizationUuids->first() : null,
                'mutual_organization_name' => $organizationNames->count() === 1 ? $organizationNames->first() : null,
                'coverage_rate' => $coverageRates->count() === 1 ? $coverageRates->first() : null,
                'subtotal_amount' => $subtotal,
                'discount_amount' => '0.00',
                'coverage_amount' => Money::fromMinor($coverageMinor),
                'total_amount' => $patientTotal,
                'paid_amount' => '0.00',
                'balance_amount' => $patientTotal,
                'created_by' => $actor->id,
            ]);

            foreach ($items as $item) {
                $invoice->lines()->create([
                    'billable_item_id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->patient_amount ?? $item->total_amount,
                    'gross_line_total' => $item->gross_amount ?? $item->total_amount,
                    'coverage_rate' => $item->coverage_rate ?? '0.00',
                    'coverage_amount' => $item->coverage_amount ?? '0.00',
                    'source_type' => $item->source_type,
                    'source_uuid' => $item->source_uuid,
                    'status' => 'ACTIVE',
                    'created_by' => $actor->id,
                ]);

                $item->status = BillableItemStatus::Invoiced;
                $item->save();
            }

            return $invoice->load('lines.billableItem', 'episode');
        });
    }

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, BillableItem>
     */
    private function selectedItems(int $episodeId, array $uuids): Collection
    {
        $uuids = collect($uuids)->filter()->unique()->values();

        if ($uuids->isEmpty()) {
            return collect();
        }

        $items = BillableItem::query()
            ->where('episode_id', $episodeId)
            ->whereIn('uuid', $uuids)
            ->lockForUpdate()
            ->get();

        if ($items->count() !== $uuids->count()) {
            throw ValidationException::withMessages([
                'billable_item_uuids' => 'Une prestation sélectionnée n’appartient pas à ce passage.',
            ]);
        }

        if ($items->contains(fn (BillableItem $item) => $item->status !== BillableItemStatus::Pending)) {
            throw ValidationException::withMessages([
                'billable_item_uuids' => 'Une prestation sélectionnée est déjà facturée ou annulée.',
            ]);
        }

        return $items;
    }
}
