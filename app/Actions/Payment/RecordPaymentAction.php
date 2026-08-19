<?php

namespace App\Actions\Payment;

use App\Enums\CashSessionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPaymentAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{invoice_uuid: string, payment_method_id: int, amount: mixed, reference?: ?string, notes?: ?string}  $data
     */
    public function execute(Patient $patient, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($patient, $data, $actor) {
            $session = CashSession::query()
                ->where('active_key', 'SINGLE_OPEN_CASH')
                ->where('status', CashSessionStatus::Open->value)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Ouvrez la caisse avant d’enregistrer un paiement.',
                ]);
            }

            $invoice = Invoice::query()
                ->where('patient_id', $patient->id)
                ->where('uuid', $data['invoice_uuid'])
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                throw ValidationException::withMessages([
                    'invoice_uuid' => 'Cette facture n’appartient pas au patient.',
                ]);
            }

            if (! in_array($invoice->status, [InvoiceStatus::Validated, InvoiceStatus::PartiallyPaid], true)) {
                throw ValidationException::withMessages([
                    'invoice_uuid' => 'Cette facture ne peut pas recevoir de paiement.',
                ]);
            }

            $method = PaymentMethod::query()
                ->whereKey($data['payment_method_id'])
                ->where('active', true)
                ->first();

            if (! $method) {
                throw ValidationException::withMessages([
                    'payment_method_id' => 'Le mode de paiement sélectionné n’est pas disponible.',
                ]);
            }

            $amountMinor = Money::toMinor($data['amount']);
            $balanceMinor = Money::toMinor($invoice->balance_amount);

            if ($amountMinor <= 0 || $amountMinor > $balanceMinor) {
                throw ValidationException::withMessages([
                    'amount' => 'Le paiement doit être supérieur à zéro et ne pas dépasser le reste à payer.',
                ]);
            }

            $paidAt = now();
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'cash_session_id' => $session->id,
                'payment_method_id' => $method->id,
                'payment_number' => $this->numbers->payment(),
                'amount' => Money::fromMinor($amountMinor),
                'currency' => $invoice->currency,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => PaymentStatus::Completed,
                'received_by' => $actor->id,
                'paid_at' => $paidAt,
            ]);

            CashMovement::create([
                'cash_session_id' => $session->id,
                'payment_id' => $payment->id,
                'payment_method_id' => $method->id,
                'type' => 'PAYMENT',
                'direction' => 'IN',
                'amount' => $payment->amount,
                'affects_cash_balance' => $method->affects_cash_balance,
                'description' => "Paiement {$payment->payment_number} — facture {$invoice->invoice_number}",
                'recorded_by' => $actor->id,
                'occurred_at' => $paidAt,
            ]);

            $receipt = Receipt::create([
                'payment_id' => $payment->id,
                'receipt_number' => $this->numbers->receipt(),
                'issued_by' => $actor->id,
                'issued_at' => $paidAt,
            ]);

            $newPaidMinor = Money::toMinor($invoice->paid_amount) + $amountMinor;
            $newBalanceMinor = $balanceMinor - $amountMinor;
            $invoice->fill([
                'paid_amount' => Money::fromMinor($newPaidMinor),
                'balance_amount' => Money::fromMinor($newBalanceMinor),
                'status' => $newBalanceMinor === 0
                    ? InvoiceStatus::Paid
                    : InvoiceStatus::PartiallyPaid,
            ])->save();

            $this->auditor->record(
                'payment.create',
                entity: $payment,
                newValues: [
                    'invoice_uuid' => $invoice->uuid,
                    'amount' => $payment->amount,
                    'payment_method' => $method->code,
                    'receipt_uuid' => $receipt->uuid,
                ],
                module: 'cash',
                actor: $actor,
            );

            return $payment->load('method', 'receipt', 'invoice');
        });
    }
}
