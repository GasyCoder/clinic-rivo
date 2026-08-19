<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateInvoiceAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages([
                    'invoice' => 'Seule une facture brouillon peut être validée.',
                ]);
            }

            if (! $invoice->lines()->where('status', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => 'La facture ne contient aucune prestation active.',
                ]);
            }

            $invoice->status = InvoiceStatus::Validated;
            $invoice->validated_by = $actor->id;
            $invoice->validated_at = now();
            $invoice->save();

            $this->auditor->record(
                'billing.validate',
                entity: $invoice,
                newValues: ['status' => InvoiceStatus::Validated->value],
                oldValues: ['status' => InvoiceStatus::Draft->value],
                module: 'billing',
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }
}
