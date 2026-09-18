<?php

namespace App\Services\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\PurchaseOrder;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierInvoice;

/**
 * ADR-098 — one serialization of suppliers, their catalogs and their prices,
 * shared by the clinic's supplier folder and the site API the central portal
 * reads. Both surfaces therefore always describe a catalog the same way.
 */
class SupplierPresenter
{
    /** @return array<string, mixed> */
    public function identity(MedicineSupplier $supplier): array
    {
        return [
            'uuid' => $supplier->uuid,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'contact_name' => $supplier->contact_name,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
            'archived' => $supplier->trashed(),
            'delete_reason' => $supplier->delete_reason,
            'deleted_at' => $supplier->deleted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function catalog(SupplierCatalog $catalog): array
    {
        return [
            'uuid' => $catalog->uuid,
            'original_name' => $catalog->original_name,
            'kind' => $catalog->kind->value,
            'kind_label' => $catalog->kind->label(),
            'size' => $catalog->size,
            'catalog_date' => $catalog->catalog_date?->toDateString(),
            'imported_at' => $catalog->imported_at?->toIso8601String(),
            'is_active' => $catalog->isActive(),
            'is_imported' => $catalog->isImported(),
            'items_count' => $catalog->items_count,
            'notes' => $catalog->notes,
            'archived' => $catalog->trashed(),
            'delete_reason' => $catalog->delete_reason,
            'creator' => $catalog->creator?->name ?? $catalog->external_created_by_name,
            'created_at' => $catalog->created_at?->toIso8601String(),
        ];
    }

    /**
     * What a supplier folder contains, as its sub-folders count it.
     *
     * @return array{catalogs: int, orders: int, open_orders: int, invoices: int, offers: int}
     */
    public function folderCounts(MedicineSupplier $supplier): array
    {
        return [
            'catalogs' => $supplier->catalogs()->count(),
            'orders' => $supplier->purchaseOrders()->count(),
            'open_orders' => $supplier->purchaseOrders()
                ->whereIn('status', [PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::PartiallyReceived->value])
                ->count(),
            'invoices' => SupplierInvoice::query()->where('medicine_supplier_id', $supplier->id)->count(),
            'offers' => $supplier->offers()->where('active_key', 'CURRENT')->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function order(PurchaseOrder $order): array
    {
        return [
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'supplier' => $order->supplier->name,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'total_amount' => $order->total_amount,
            'ordered_at' => $order->ordered_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * Expects supplier, lines.medicine.catalogItem, receipts.lines,
     * receipts.receivedBy and invoices to be loaded.
     *
     * @return array<string, mixed>
     */
    public function orderDetail(PurchaseOrder $order): array
    {
        return [
            ...$this->order($order),
            'expected_delivery_at' => $order->expected_delivery_at?->toDateString(),
            'notes' => $order->notes,
            'cancellation_reason' => $order->cancellation_reason,
            'supplier_uuid' => $order->supplier->uuid,
            'created_by_name' => $order->creator?->name ?? $order->external_created_by_name,
            'lines' => $order->lines->map(fn ($line) => [
                'id' => $line->id,
                'medicine_uuid' => $line->medicine->uuid,
                'medicine_name' => $line->medicine->catalogItem?->name,
                'medicine_code' => $line->medicine->catalogItem?->code,
                'quantity_ordered' => $line->quantity_ordered,
                'quantity_received' => $line->quantity_received,
                'quantity_remaining' => $line->quantityRemaining(),
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
            ])->values(),
            'receipts' => $order->receipts->map(fn ($receipt) => [
                'uuid' => $receipt->uuid,
                'receipt_number' => $receipt->receipt_number,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'received_by' => $receipt->receivedBy?->name,
                'lines_count' => $receipt->lines->count(),
            ])->values(),
            'invoices' => $order->invoices->map(fn ($invoice) => [
                'uuid' => $invoice->uuid,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'total_amount' => $invoice->total_amount,
            ])->values(),
        ];
    }

    /**
     * Expects supplier, lines.medicine.catalogItem, purchaseOrder and
     * goodsReceipt to be loaded.
     *
     * @return array<string, mixed>
     */
    public function invoiceDetail(SupplierInvoice $invoice): array
    {
        return [
            ...$this->invoice($invoice),
            'notes' => $invoice->notes,
            'archived' => $invoice->trashed(),
            'delete_reason' => $invoice->delete_reason,
            'purchase_order_uuid' => $invoice->purchaseOrder?->uuid,
            'purchase_order_number' => $invoice->purchaseOrder?->order_number,
            'goods_receipt_uuid' => $invoice->goodsReceipt?->uuid,
            'goods_receipt_number' => $invoice->goodsReceipt?->receipt_number,
            'created_by_name' => $invoice->creator?->name ?? $invoice->external_created_by_name,
            'lines' => $invoice->lines->map(fn ($line) => [
                'medicine_uuid' => $line->medicine->uuid,
                'medicine_name' => $line->medicine->catalogItem?->name,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function invoice(SupplierInvoice $invoice): array
    {
        return [
            'uuid' => $invoice->uuid,
            'invoice_number' => $invoice->invoice_number,
            'supplier' => $invoice->supplier->name,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'total_amount' => $invoice->total_amount,
            'has_attachment' => $invoice->hasAttachment(),
            // The list offers « restaurer » on a withdrawn invoice: it has to
            // know which ones are in the bin (ADR-009).
            'archived' => $invoice->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    public function offer(MedicineSupplierOffer $offer): array
    {
        return [
            'uuid' => $offer->uuid,
            'medicine_uuid' => $offer->medicine->uuid,
            'medicine_name' => $offer->medicine->catalogItem?->name,
            'medicine_code' => $offer->medicine->catalogItem?->code,
            'supplier_reference' => $offer->supplier_reference,
            // ADR-098 — the name this supplier gives the medicine, as read in its catalog.
            'supplier_label' => SupplierCatalogItem::query()
                ->where('linked_medicine_id', $offer->medicine_id)
                ->whereHas('catalog', fn ($query) => $query->withTrashed()->where('medicine_supplier_id', $offer->medicine_supplier_id))
                ->latest('id')
                ->value('medicine_label'),
            'quoted_price' => $offer->quoted_price,
            'effective_from' => $offer->effective_from?->toIso8601String(),
            'effective_until' => $offer->effective_until?->toIso8601String(),
            'change_reason' => $offer->change_reason,
            // Where this line comes from, and whether the clinic ever
            // actually received it: a supplier catalogue proposes, a
            // reception is what makes a product really the pharmacy's.
            'source_catalog' => $offer->sourceCatalogItem?->catalog()->withTrashed()->value('original_name'),
            'received_at' => $offer->medicine->lots()->max('created_at'),
            'in_stock' => $offer->medicine->lots()
                ->where('active', true)
                ->whereDate('expires_at', '>=', now()->toDateString())
                ->sum('quantity_on_hand'),
        ];
    }
}
