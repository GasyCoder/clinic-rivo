<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientType;
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
        // ADR-030: a staff benefit is not a zero tariff or an arbitrary
        // discount. Until RH / Finance has resolved the employee coverage
        // and the surgery-credit share, creating a normal patient invoice
        // here would overcharge the employee and bypass that ledger.
        if ($patient->patient_type === PatientType::Staff) {
            throw ValidationException::withMessages([
                'patient' => 'La couverture Personnel doit être calculée par RH / Finance avant toute facturation.',
            ]);
        }

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

            $subtotalMinor = $items->sum(fn (BillableItem $item) => Money::toMinor($item->total_amount));

            if ($subtotalMinor <= 0 || $subtotalMinor > 999_999_999_999_999) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'Le montant total de la facture est invalide ou dépasse la limite autorisée.',
                ]);
            }

            $subtotal = Money::fromMinor($subtotalMinor);
            $invoice = Invoice::create([
                'patient_id' => $patient->id,
                'episode_id' => $episode->id,
                'invoice_number' => $this->numbers->invoice(),
                'status' => InvoiceStatus::Draft,
                'currency' => 'MGA',
                'subtotal_amount' => $subtotal,
                'discount_amount' => '0.00',
                'total_amount' => $subtotal,
                'paid_amount' => '0.00',
                'balance_amount' => $subtotal,
                'created_by' => $actor->id,
            ]);

            foreach ($items as $item) {
                $invoice->lines()->create([
                    'billable_item_id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->total_amount,
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
