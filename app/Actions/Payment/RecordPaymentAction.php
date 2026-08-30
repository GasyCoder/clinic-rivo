<?php

namespace App\Actions\Payment;

use App\Enums\CashSessionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Models\CashMovement;
use App\Models\CashRegister;
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
     * @param  array{invoice_uuid: string, payment_method_id: int, amount: mixed, reference?: ?string, notes?: ?string, cash_register_uuid?: ?string}  $data
     */
    public function execute(Patient|Invoice $payer, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($payer, $data, $actor) {
            $session = $this->resolveOpenSession($data['cash_register_uuid'] ?? null, $actor);

            $invoice = Invoice::query()
                ->when($payer instanceof Patient, fn ($query) => $query->where('patient_id', $payer->id))
                ->when($payer instanceof Invoice, fn ($query) => $query->whereKey($payer->getKey()))
                ->where('uuid', $data['invoice_uuid'])
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                throw ValidationException::withMessages([
                    'invoice_uuid' => 'Cette facture ne correspond pas au dossier d’encaissement.',
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

            if ($newBalanceMinor === 0) {
                $dispense = $invoice->pharmacyDispense()->lockForUpdate()->first();

                if ($dispense && $dispense->status === PharmacyDispenseStatus::AwaitingPayment) {
                    $dispense->update(['status' => PharmacyDispenseStatus::Ready]);
                }
            }

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

    /**
     * Explicit register wins outright — every caller that knows which till
     * it's working from (the /cash workspace, the arrival "payer maintenant"
     * flow) should pass it. Without one, auto-resolve only when unambiguous:
     * this keeps every existing caller working exactly as before as long as
     * at most one session is open anywhere on the site, and fails loudly
     * rather than guessing once a second register is open concurrently.
     *
     * Either way, only the session's own opener may pay into it — a caisse
     * already open by someone else is never silently usable, even if it's
     * the only one open on the site: the actor simply has none of their own.
     */
    private function resolveOpenSession(?string $cashRegisterUuid, User $actor): CashSession
    {
        if ($cashRegisterUuid !== null) {
            $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->first();

            if (! $register) {
                throw ValidationException::withMessages([
                    'cash_register_uuid' => 'Cette caisse n’est plus disponible.',
                ]);
            }

            $session = CashSession::query()
                ->where('active_key', CashSession::activeKeyFor($register))
                ->where('status', CashSessionStatus::Open->value)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_session' => "La caisse « {$register->name} » n’est pas ouverte.",
                ]);
            }

            if ($session->opened_by !== $actor->id) {
                throw ValidationException::withMessages([
                    'cash_register_uuid' => 'Cette caisse est utilisée par une autre personne. Utilisez une autre caisse disponible.',
                ]);
            }

            return $session;
        }

        $openSessions = CashSession::query()
            ->where('status', CashSessionStatus::Open->value)
            ->where('opened_by', $actor->id)
            ->whereNotNull('active_key')
            ->lockForUpdate()
            ->get();

        if ($openSessions->isEmpty()) {
            throw ValidationException::withMessages([
                'cash_session' => 'Ouvrez la caisse avant d’enregistrer un paiement.',
            ]);
        }

        if ($openSessions->count() > 1) {
            throw ValidationException::withMessages([
                'cash_register_uuid' => 'Plusieurs caisses sont ouvertes. Choisissez la caisse concernée.',
            ]);
        }

        return $openSessions->first();
    }
}
