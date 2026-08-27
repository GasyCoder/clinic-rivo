<?php

namespace App\Actions\Pharmacy;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\RecordBillableItemAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Enums\BillableItemStatus;
use App\Enums\CatalogTariffCategory;
use App\Enums\InvoiceStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Enums\PharmacyDispenseType;
use App\Models\BillableItem;
use App\Models\CatalogTariff;
use App\Models\Invoice;
use App\Models\PharmacyDispense;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareDispenseInvoiceAction
{
    public function __construct(
        private readonly RecordBillableItemAction $recordBillableItem,
        private readonly CreateInvoiceAction $createInvoice,
        private readonly ValidateInvoiceAction $validateInvoice,
        private readonly FinancialNumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    public function execute(PharmacyDispense $dispense, User $actor): PharmacyDispense
    {
        return DB::transaction(function () use ($dispense, $actor): PharmacyDispense {
            $dispense = PharmacyDispense::query()
                ->with(['lines.medicine.catalogItem', 'episode.patient'])
                ->lockForUpdate()
                ->findOrFail($dispense->getKey());

            if ($dispense->invoice_id !== null) {
                return $dispense;
            }

            if ($dispense->status !== PharmacyDispenseStatus::AwaitingInvoice) {
                throw ValidationException::withMessages(['dispense' => 'Cette demande ne peut plus être facturée.']);
            }

            $invoice = $dispense->type === PharmacyDispenseType::Internal
                ? $this->internalInvoice($dispense, $actor)
                : $this->externalInvoice($dispense, $actor);

            $invoice = $this->validateInvoice->execute($invoice, $actor);
            $dispense->update([
                'invoice_id' => $invoice->getKey(),
                'status' => $invoice->status === InvoiceStatus::Covered
                    ? PharmacyDispenseStatus::Ready
                    : PharmacyDispenseStatus::AwaitingPayment,
            ]);

            $this->auditor->record(
                'pharmacy.invoice_prepared',
                entity: $dispense,
                newValues: ['invoice_uuid' => $invoice->uuid, 'invoice_status' => $invoice->status->value],
                module: 'pharmacy',
                actor: $actor,
            );

            return $dispense->fresh(['invoice', 'lines.billableItem']);
        });
    }

    private function internalInvoice(PharmacyDispense $dispense, User $actor): Invoice
    {
        if (! $dispense->episode || ! $dispense->patient_id) {
            throw ValidationException::withMessages(['dispense' => 'Le patient ou le passage de cette ordonnance est introuvable.']);
        }

        $items = collect();

        foreach ($dispense->lines as $line) {
            $item = $this->recordBillableItem->execute($dispense->episode, [
                'catalog_item_uuid' => $line->medicine->catalogItem->uuid,
                'quantity' => $line->quantity_requested,
                'payment_required_before_fulfillment' => true,
            ], $actor, $dispense);
            $line->update(['billable_item_id' => $item->getKey()]);
            $items->push($item);
        }

        $invoice = $this->createInvoice->execute($dispense->episode->patient, [
            'episode_uuid' => $dispense->episode->uuid,
            'billable_item_uuids' => $items->pluck('uuid')->all(),
        ], $actor);
        $invoice->update(['source_module' => 'PHARMACY']);

        return $invoice;
    }

    private function externalInvoice(PharmacyDispense $dispense, User $actor): Invoice
    {
        $items = collect();

        foreach ($dispense->lines as $line) {
            $catalog = $line->medicine->catalogItem;
            $tariff = CatalogTariff::query()
                ->where('catalog_item_id', $catalog->getKey())
                ->where('tariff_category', CatalogTariffCategory::Standard->value)
                ->where('active_key', 'CURRENT')
                ->lockForUpdate()
                ->first();

            if (! $catalog->billable || ! $tariff) {
                throw ValidationException::withMessages([
                    'lines' => "Le prix de vente Sans mutuelle de {$catalog->name} n’est pas configuré.",
                ]);
            }

            $totalMinor = Money::multiply($line->quantity_requested, $tariff->amount);
            $item = BillableItem::query()->create([
                'episode_id' => null,
                'source_module' => 'PHARMACY',
                'source_type' => $dispense->getMorphClass(),
                'source_id' => $dispense->getKey(),
                'source_uuid' => $dispense->uuid,
                'catalog_item_id' => $catalog->getKey(),
                'catalog_tariff_id' => $tariff->getKey(),
                'tariff_category' => CatalogTariffCategory::Standard,
                'coverage_rate' => '0.00',
                'description' => $catalog->name,
                'quantity' => Money::normalize($line->quantity_requested),
                'unit_price' => $tariff->amount,
                'total_amount' => Money::fromMinor($totalMinor),
                'gross_amount' => Money::fromMinor($totalMinor),
                'coverage_amount' => '0.00',
                'patient_amount' => Money::fromMinor($totalMinor),
                'currency' => $tariff->currency,
                'payment_required_before_fulfillment' => true,
                'status' => BillableItemStatus::Pending,
                'created_by' => $actor->getKey(),
            ]);
            $line->update(['billable_item_id' => $item->getKey()]);
            $items->push($item);
        }

        $totalMinor = $items->sum(fn (BillableItem $item) => Money::toMinor($item->total_amount));
        $invoice = Invoice::query()->create([
            'patient_id' => null,
            'episode_id' => null,
            'customer_type' => 'EXTERNAL',
            'customer_name' => $dispense->customer_name,
            'customer_phone' => $dispense->customer_phone,
            'source_module' => 'PHARMACY',
            'invoice_number' => $this->numbers->invoice(),
            'status' => InvoiceStatus::Draft,
            'currency' => 'MGA',
            'subtotal_amount' => Money::fromMinor($totalMinor),
            'discount_amount' => '0.00',
            'coverage_amount' => '0.00',
            'total_amount' => Money::fromMinor($totalMinor),
            'paid_amount' => '0.00',
            'balance_amount' => Money::fromMinor($totalMinor),
            'created_by' => $actor->getKey(),
        ]);

        foreach ($items as $item) {
            $invoice->lines()->create([
                'billable_item_id' => $item->getKey(),
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->total_amount,
                'gross_line_total' => $item->gross_amount,
                'coverage_rate' => '0.00',
                'coverage_amount' => '0.00',
                'source_type' => $item->source_type,
                'source_uuid' => $item->source_uuid,
                'status' => 'ACTIVE',
                'created_by' => $actor->getKey(),
            ]);
            $item->update(['status' => BillableItemStatus::Invoiced]);
        }

        return $invoice;
    }
}
