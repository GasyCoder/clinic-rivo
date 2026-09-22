<?php

namespace App\Actions\Pharmacy;

use App\Actions\Pharmacy\Concerns\BuildsSupplierInvoiceContent;
use App\Models\SupplierInvoice;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADR-098 — corrects a supplier invoice that was mis-typed. Like recording it,
 * a correction never touches the stock (ADR-097). The supplier cannot change:
 * an invoice from another supplier is another invoice. A new document replaces
 * the previous one only once the correction is saved; without a new document,
 * the existing one is kept.
 */
class UpdateSupplierInvoiceAction
{
    use BuildsSupplierInvoiceContent;

    /** @param array<string, mixed> $data */
    public function execute(SupplierInvoice $invoice, array $data, CatalogActor $actor): SupplierInvoice
    {
        if ($actor->cannot('supplier_invoices.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier cette facture.');
        }

        if ($invoice->trashed()) {
            throw ValidationException::withMessages(['invoice' => 'Restaurez la facture avant de la modifier.']);
        }

        /** @var ?UploadedFile $file */
        $file = $data['attachment'] ?? null;
        $supplier = $invoice->supplier()->withTrashed()->firstOrFail();
        $newPath = $file ? $file->store("suppliers/{$supplier->uuid}/invoices/".now()->format('Y/m'), 'local') : null;
        $previousPath = $invoice->attachment_path;

        try {
            $invoice = DB::transaction(function () use ($invoice, $supplier, $data, $actor, $file, $newPath): SupplierInvoice {
                $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
                [$purchaseOrder, $goodsReceipt] = $this->resolveLinks($supplier, $data);
                [$lines, $total] = $this->resolveContent($data);
                $invoiceNumber = $this->guardInvoiceNumber($supplier, $data['invoice_number'], $invoice);

                $invoice->update([
                    'invoice_number' => $invoiceNumber,
                    'purchase_order_id' => $purchaseOrder?->getKey(),
                    'goods_receipt_id' => $goodsReceipt?->getKey(),
                    'invoice_date' => $data['invoice_date'],
                    'due_date' => $data['due_date'] ?? null,
                    'total_amount' => $total,
                    ...($file && $newPath ? $this->attachmentColumns($file, $newPath) : []),
                    'notes' => $data['notes'] ?? null,
                    'updated_by' => $actor->localUserId() ?? $invoice->updated_by,
                    ...$actor->externalAttribution('updated'),
                ]);

                $invoice->lines()->delete();

                foreach ($lines as $line) {
                    $invoice->lines()->create($line);
                }

                return $invoice->fresh('lines');
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath && $previousPath) {
            Storage::disk('local')->delete($previousPath);
        }

        return $invoice;
    }
}
