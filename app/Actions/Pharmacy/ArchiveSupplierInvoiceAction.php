<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierInvoice;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class ArchiveSupplierInvoiceAction
{
    public function execute(SupplierInvoice $invoice, string $reason, CatalogActor $actor): SupplierInvoice
    {
        if ($actor->cannot('supplier_invoices.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver cette facture.');
        }

        $invoice->delete_reason = trim($reason);
        $invoice->delete();

        return $invoice;
    }
}
