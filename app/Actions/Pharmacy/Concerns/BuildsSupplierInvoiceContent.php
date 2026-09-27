<?php

namespace App\Actions\Pharmacy\Concerns;

use App\Models\GoodsReceipt;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The content of a supplier invoice — its order/reception links and its lines
 * — built identically when it is recorded and when it is corrected.
 */
trait BuildsSupplierInvoiceContent
{
    /**
     * Deux factures d'un même fournisseur ne peuvent pas porter le même numéro
     * — la base l'impose (`unique(medicine_supplier_id, invoice_number)`).
     *
     * Sans ce contrôle, le doublon remontait en violation de contrainte : une
     * erreur 500 pour le pharmacien, et une pile d'appels dans `laravel.log`
     * au lieu d'un message sous le champ. L'index couvre aussi les factures
     * archivées, ce que le message doit dire : le numéro n'est pas libre, il
     * est simplement rangé.
     */
    private function guardInvoiceNumber(MedicineSupplier $supplier, string $number, ?SupplierInvoice $current = null): string
    {
        $number = trim($number);

        $existing = SupplierInvoice::query()
            ->withTrashed()
            ->where('medicine_supplier_id', $supplier->getKey())
            ->where('invoice_number', $number)
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'invoice_number' => $existing->trashed()
                    ? "Une facture archivée de {$supplier->name} porte déjà le numéro {$number} : restaurez-la depuis la Corbeille."
                    : "Une facture de {$supplier->name} porte déjà le numéro {$number}.",
            ]);
        }

        return $number;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?PurchaseOrder, 1: ?GoodsReceipt}
     */
    private function resolveLinks(MedicineSupplier $supplier, array $data): array
    {
        $purchaseOrder = filled($data['purchase_order_uuid'] ?? null)
            ? PurchaseOrder::query()->where('uuid', $data['purchase_order_uuid'])->firstOrFail()
            : null;
        $goodsReceipt = filled($data['goods_receipt_uuid'] ?? null)
            ? GoodsReceipt::query()->where('uuid', $data['goods_receipt_uuid'])->firstOrFail()
            : null;

        // An invoice documents what this supplier delivered: it cannot
        // point to another supplier's order or reception.
        if ($purchaseOrder && $purchaseOrder->medicine_supplier_id !== $supplier->getKey()) {
            throw ValidationException::withMessages([
                'purchase_order_uuid' => 'Cette commande a été passée à un autre fournisseur.',
            ]);
        }

        if ($goodsReceipt && (
            $goodsReceipt->purchaseOrder?->medicine_supplier_id !== $supplier->getKey()
            || ($purchaseOrder && $goodsReceipt->purchase_order_id !== $purchaseOrder->getKey())
        )) {
            throw ValidationException::withMessages([
                'goods_receipt_uuid' => 'Cette réception ne correspond pas à ce fournisseur ou à cette commande.',
            ]);
        }

        return [$purchaseOrder, $goodsReceipt];
    }

    /**
     * An invoice is a financial document first: detailed line by line when the
     * supplier's document is, or reduced to its total when it is not. The two
     * never coexist as separate truths — with lines, the total is their sum,
     * so a typed total can never contradict what is listed under it.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function resolveContent(array $data): array
    {
        $lines = $data['lines'] ?? [];

        if ($lines !== []) {
            return $this->buildLines($lines);
        }

        if (! filled($data['total_amount'] ?? null)) {
            throw ValidationException::withMessages([
                'total_amount' => 'Indiquez le montant total de la facture.',
            ]);
        }

        return [[], Money::normalize((string) $data['total_amount'])];
    }

    /**
     * @param  array<int, array<string, mixed>>  $input
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function buildLines(array $input): array
    {
        $totalMinor = 0;
        $lines = [];

        foreach ($input as $line) {
            $medicine = Medicine::query()->where('uuid', $line['medicine_uuid'])->firstOrFail();
            $lineTotalMinor = Money::multiply($line['quantity'], $line['unit_price']);
            $totalMinor += $lineTotalMinor;

            $lines[] = [
                'medicine_id' => $medicine->getKey(),
                'description' => trim($line['description']),
                'quantity' => (int) $line['quantity'],
                'unit_price' => Money::normalize($line['unit_price']),
                'line_total' => Money::fromMinor($lineTotalMinor),
            ];
        }

        return [$lines, Money::fromMinor($totalMinor)];
    }

    /** @return array{attachment_path: string, attachment_original_name: string, attachment_mime_type: string, attachment_size: int|false} */
    private function attachmentColumns(UploadedFile $file, string $path): array
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', basename($file->getClientOriginalName())) ?: 'facture';

        return [
            'attachment_path' => $path,
            'attachment_original_name' => Str::limit($name, 255, ''),
            'attachment_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'attachment_size' => $file->getSize(),
        ];
    }
}
