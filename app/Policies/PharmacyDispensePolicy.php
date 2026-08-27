<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Models\PharmacyDispense;
use App\Models\User;

class PharmacyDispensePolicy
{
    public function prepareInvoice(User $user, PharmacyDispense $dispense): bool
    {
        return $user->can('pharmacy.dispense.prepare_invoice')
            && $dispense->status === PharmacyDispenseStatus::AwaitingInvoice
            && $dispense->invoice_id === null;
    }

    public function dispense(User $user, PharmacyDispense $dispense): bool
    {
        $dispense->loadMissing('invoice');

        return $user->can('pharmacy.dispense')
            && $dispense->status->canDispense()
            && $dispense->invoice !== null
            && in_array($dispense->invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Covered], true);
    }
}
