<?php

namespace App\Actions\StaffDebts;

use App\Enums\CashSessionStatus;
use App\Enums\StaffDebtRepaymentSource;
use App\Enums\StaffDebtStatus;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Cash\OwnOpenCashSession;
use App\Services\Finance\FinancialNumberGenerator;
use App\Services\StaffDebts\StaffDebtLedger;
use App\Services\StaffDebts\StaffDebtNotifier;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-228 — un membre du personnel rembourse sa dette en espèces : seule la Caisse
 * encaisse (ADR-012). L'argent entre dans la session de caisse de celui qui encaisse
 * (ADR-058, ADR-059), compte à sa clôture, et un reçu est remis. Une dette retenue sur
 * salaire peut aussi être remboursée en avance de cette façon : le reste dû baisse, et
 * la paie ne retient plus que ce qui reste.
 *
 * Annuler un encaissement (erreur de caisse) se fait dans la même caisse encore
 * ouverte, par celui qui l'a ouverte — la règle de l'annulation d'un paiement.
 */
class CollectStaffDebtRepaymentAction
{
    public const MOVEMENT_TYPE = 'STAFF_DEBT_REPAYMENT';

    public const REVERSAL_TYPE = 'STAFF_DEBT_REPAYMENT_CANCELLATION';

    public function __construct(
        private readonly OwnOpenCashSession $sessions,
        private readonly FinancialNumberGenerator $numbers,
        private readonly StaffDebtLedger $ledger,
        private readonly StaffDebtNotifier $notifier,
        private readonly Auditor $auditor,
    ) {}

    /** @param  array{amount: string, cash_register_uuid?: ?string, note?: ?string}  $data */
    public function execute(StaffDebt $debt, array $data, User $actor): StaffDebtRepayment
    {
        if ($actor->cannot('staff_debts.collect')) {
            throw new AuthorizationException('Seule la Caisse encaisse un remboursement de dette.');
        }

        $amountMinor = Money::toMinor((string) $data['amount']);

        [$repayment, $settled] = DB::transaction(function () use ($debt, $data, $amountMinor, $actor): array {
            $session = $this->sessions->resolve($data['cash_register_uuid'] ?? null, $actor, 'Ouvrez la caisse avant d’encaisser un remboursement.');
            $debt = StaffDebt::query()->with('repayments')->lockForUpdate()->findOrFail($debt->getKey());

            if ($debt->status !== StaffDebtStatus::Active) {
                throw ValidationException::withMessages(['debt' => match ($debt->status) {
                    StaffDebtStatus::Approved => 'Cette dette n’est pas encore versée : rien n’est à rembourser.',
                    StaffDebtStatus::Settled => 'Cette dette est déjà soldée.',
                    default => 'Cette dette n’attend aucun remboursement.',
                }]);
            }

            $balance = $debt->balanceMinor();
            if ($amountMinor <= 0 || $amountMinor > $balance) {
                throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à zéro et ne pas dépasser le reste dû ('.StaffDebtNotifier::money(Money::fromMinor($balance)).').']);
            }

            $method = PaymentMethod::query()->where('code', 'CASH')->first();
            $now = now();
            $number = $this->numbers->staffDebtReceipt();

            $movement = CashMovement::query()->create([
                'cash_session_id' => $session->getKey(),
                'payment_method_id' => $method?->getKey(),
                'type' => self::MOVEMENT_TYPE,
                'direction' => 'IN',
                'amount' => Money::fromMinor($amountMinor),
                // Des espèces remises en main propre : elles sont dans le tiroir.
                'affects_cash_balance' => true,
                'description' => "Remboursement {$number} — dette {$debt->number} ({$debt->employee_name})",
                'recorded_by' => $actor->getKey(),
                'occurred_at' => $now,
            ]);

            $repayment = StaffDebtRepayment::query()->create([
                'staff_debt_id' => $debt->getKey(),
                'employee_id' => $debt->employee_id,
                'source' => StaffDebtRepaymentSource::Cash,
                'period' => $now->copy()->startOfMonth()->toDateString(),
                'amount' => Money::fromMinor($amountMinor),
                'cash_session_id' => $session->getKey(),
                'cash_movement_id' => $movement->getKey(),
                'receipt_number' => $number,
                'note' => filled($data['note'] ?? null) ? Str::squish($data['note']) : null,
                'recorded_at' => $now,
                'recorded_by' => $actor->getKey(),
            ]);

            $settled = $this->ledger->refreshStatus($debt);

            $this->auditor->record('staff_debt.repayment.collect', entity: $debt, newValues: [
                'repayment_uuid' => $repayment->uuid, 'amount' => $repayment->amount, 'receipt_number' => $number,
                'cash_session_uuid' => $session->uuid, 'balance' => Money::fromMinor($debt->balanceMinor()),
            ], module: 'cash', actor: $actor);

            return [$repayment, $settled ? $debt : null];
        });

        if ($settled !== null) {
            $this->notifier->employee($settled, 'settled', 'Votre dette '.$settled->number.' est soldée', 'Tout est remboursé. Merci.');
        }

        return $repayment;
    }

    public function reverse(StaffDebtRepayment $repayment, string $reason, User $actor): StaffDebtRepayment
    {
        if ($actor->cannot('staff_debts.collect')) {
            throw new AuthorizationException('Seule la Caisse annule un encaissement.');
        }

        $reason = Str::squish($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif d’annulation est obligatoire.']);
        }

        return DB::transaction(function () use ($repayment, $reason, $actor): StaffDebtRepayment {
            $repayment = StaffDebtRepayment::query()->lockForUpdate()->findOrFail($repayment->getKey());

            if ($repayment->source !== StaffDebtRepaymentSource::Cash) {
                throw ValidationException::withMessages(['repayment' => 'Une retenue sur la paie ne s’annule pas ici : elle s’annule avec la paie qui l’a portée.']);
            }

            if ($repayment->reversed_at !== null) {
                throw ValidationException::withMessages(['repayment' => 'Cet encaissement est déjà annulé.']);
            }

            $session = CashSession::query()->lockForUpdate()->findOrFail($repayment->cash_session_id);
            if ($session->status !== CashSessionStatus::Open) {
                throw ValidationException::withMessages(['repayment' => 'Cet encaissement appartient à une caisse clôturée : il ne s’annule plus ici.']);
            }

            if ($session->opened_by !== $actor->getKey()) {
                throw ValidationException::withMessages(['repayment' => 'Cet encaissement a été fait dans la caisse d’une autre personne : seule elle peut l’annuler.']);
            }

            $debt = StaffDebt::query()->lockForUpdate()->findOrFail($repayment->staff_debt_id);
            $movement = CashMovement::query()->find($repayment->cash_movement_id);

            $reversal = CashMovement::query()->create([
                'cash_session_id' => $session->getKey(),
                'payment_method_id' => $movement?->payment_method_id,
                'type' => self::REVERSAL_TYPE,
                'direction' => 'OUT',
                'amount' => $repayment->amount,
                'affects_cash_balance' => (bool) ($movement?->affects_cash_balance ?? true),
                'description' => "Annulation du remboursement {$repayment->receipt_number} — dette {$debt->number}",
                'recorded_by' => $actor->getKey(),
                'occurred_at' => now(),
            ]);

            $repayment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->getKey(),
                'reverse_reason' => $reason,
                'reversal_cash_movement_id' => $reversal->getKey(),
            ])->save();

            $this->ledger->refreshStatus($debt);

            $this->auditor->record('staff_debt.repayment.reverse', entity: $debt, newValues: [
                'repayment_uuid' => $repayment->uuid, 'amount' => $repayment->amount, 'receipt_number' => $repayment->receipt_number,
                'balance' => Money::fromMinor($debt->balanceMinor()),
            ], reason: $reason, module: 'cash', actor: $actor);

            return $repayment;
        });
    }
}
