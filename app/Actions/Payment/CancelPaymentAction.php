<?php

namespace App\Actions\Payment;

use App\Enums\CashSessionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPaymentAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(Payment $payment, string $reason, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $reason = trim($reason);

            if ($payment->status !== PaymentStatus::Completed) {
                throw ValidationException::withMessages([
                    'payment' => 'Ce paiement est déjà annulé.',
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Le motif d’annulation est obligatoire.',
                ]);
            }

            $session = CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);

            // A closed till is an immutable accounting period. Reversing it
            // requires the future refund/correction workflow, whose approval
            // rules are not yet defined in the CDC.
            if ($session->status !== CashSessionStatus::Open || $session->active_key !== 'SINGLE_OPEN_CASH') {
                throw ValidationException::withMessages([
                    'payment' => 'Ce paiement appartient à une caisse clôturée. Utilisez le futur circuit de remboursement contrôlé.',
                ]);
            }

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $dispense = $invoice->pharmacyDispense()->with('lines')->lockForUpdate()->first();

            if ($dispense && $dispense->lines->sum('quantity_dispensed') > 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Ce paiement finance une délivrance Pharmacie déjà commencée et ne peut pas être annulé.',
                ]);
            }
            $amountMinor = Money::toMinor($payment->amount);
            $paidMinor = Money::toMinor($invoice->paid_amount);

            if ($amountMinor > $paidMinor) {
                throw ValidationException::withMessages([
                    'payment' => 'Les montants de la facture sont incohérents. L’annulation est interrompue.',
                ]);
            }

            $cancelledAt = now();

            CashMovement::create([
                'cash_session_id' => $session->id,
                'payment_id' => null,
                'reversal_payment_id' => $payment->id,
                'payment_method_id' => $payment->payment_method_id,
                'type' => 'PAYMENT_CANCELLATION',
                'direction' => 'OUT',
                'amount' => $payment->amount,
                'affects_cash_balance' => $payment->method()->value('affects_cash_balance'),
                'description' => "Annulation paiement {$payment->payment_number} — facture {$invoice->invoice_number}",
                'recorded_by' => $actor->id,
                'occurred_at' => $cancelledAt,
            ]);

            $payment->fill([
                'status' => PaymentStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => $cancelledAt,
                'cancellation_reason' => $reason,
            ])->save();

            $newPaidMinor = $paidMinor - $amountMinor;
            $newBalanceMinor = Money::toMinor($invoice->total_amount) - $newPaidMinor;
            $invoice->fill([
                'paid_amount' => Money::fromMinor($newPaidMinor),
                'balance_amount' => Money::fromMinor($newBalanceMinor),
                'status' => $newPaidMinor === 0
                    ? InvoiceStatus::Validated
                    : InvoiceStatus::PartiallyPaid,
            ])->save();

            if ($dispense) {
                $dispense->update(['status' => PharmacyDispenseStatus::AwaitingPayment]);
            }

            $this->auditor->record(
                'payment.cancel',
                entity: $payment,
                oldValues: ['status' => PaymentStatus::Completed->value],
                newValues: [
                    'status' => PaymentStatus::Cancelled->value,
                    'invoice_uuid' => $invoice->uuid,
                    'amount' => $payment->amount,
                ],
                reason: $reason,
                module: 'cash',
                actor: $actor,
            );

            return $payment->load('method', 'receipt', 'invoice', 'canceller');
        });
    }
}
