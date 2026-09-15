<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierInvoice;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class RestoreSupplierInvoiceAction
{
    public function execute(SupplierInvoice $invoice, CatalogActor $actor): SupplierInvoice
    {
        if ($actor->cannot('supplier_invoices.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer cette facture.');
        }

        $invoice->forceFill([
            'updated_by' => $actor->localUserId(),
            ...$actor->externalAttribution('updated'),
        ]);
        $invoice->restore();

        return $invoice;
    }
}
