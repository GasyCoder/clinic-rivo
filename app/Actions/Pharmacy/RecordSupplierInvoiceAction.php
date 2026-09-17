<?php

namespace App\Actions\Pharmacy;

use App\Actions\Pharmacy\Concerns\BuildsSupplierInvoiceContent;
use App\Models\MedicineSupplier;
use App\Models\SupplierInvoice;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ADR-097 — spec §10: a purely administrative bookkeeping record. It never
 * touches medicines/lots/movements — that impact was already fully
 * recorded by ReceiveGoodsAction at reception time.
 */
class RecordSupplierInvoiceAction
{
    use BuildsSupplierInvoiceContent;

    /**
     * @param array{
     *   invoice_number: string,
     *   invoice_date: string,
     *   purchase_order_uuid?: ?string,
     *   goods_receipt_uuid?: ?string,
     *   notes?: ?string,
     *   attachment?: ?UploadedFile,
     *   total_amount?: ?string,
     *   lines?: array<int, array{medicine_uuid: string, description: string, quantity: int, unit_price: string}>
     * } $data
     */
    public function execute(MedicineSupplier $supplier, array $data, CatalogActor $actor): SupplierInvoice
    {
        if ($actor->cannot('supplier_invoices.create')) {
            throw new AuthorizationException('Vous ne pouvez pas enregistrer cette facture.');
        }

        $attachmentPath = null;

        if (! empty($data['attachment'])) {
            $attachmentPath = $data['attachment']->store(
                "suppliers/{$supplier->uuid}/invoices/".now()->format('Y/m'),
                'local',
            );
        }

        try {
            return DB::transaction(function () use ($supplier, $data, $actor, $attachmentPath): SupplierInvoice {
                [$purchaseOrder, $goodsReceipt] = $this->resolveLinks($supplier, $data);
                [$lines, $total] = $this->resolveContent($data);

                $invoice = SupplierInvoice::query()->create([
                    'invoice_number' => trim($data['invoice_number']),
                    'medicine_supplier_id' => $supplier->getKey(),
                    'purchase_order_id' => $purchaseOrder?->getKey(),
                    'goods_receipt_id' => $goodsReceipt?->getKey(),
                    'invoice_date' => $data['invoice_date'],
                    'total_amount' => $total,
                    'currency' => 'MGA',
                    ...($attachmentPath ? $this->attachmentColumns($data['attachment'], $attachmentPath) : []),
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $actor->localUserId(),
                    'updated_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('created'),
                    ...$actor->externalAttribution('updated'),
                ]);

                foreach ($lines as $line) {
                    $invoice->lines()->create($line);
                }

                return $invoice->fresh('lines');
            });
        } catch (Throwable $exception) {
            if ($attachmentPath) {
                Storage::disk('local')->delete($attachmentPath);
            }

            throw $exception;
        }
    }
}
