<?php

namespace App\Actions\StaffDebts;

use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtRepaymentSource;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\StaffDebts\StaffDebtLedger;
use App\Services\StaffDebts\StaffDebtNotifier;
use App\Services\StaffDebts\StaffDebtPenalties;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use App\Support\StaffDebts\StaffDebtTerms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-230 — le règlement au départ, négocié entre le DG et la personne qui quitte la
 * clinique. Le reste dû se répartit, seul ou combiné :
 *
 *   retenue sur le solde de tout compte   retenue hors RIVO, comme la paie, constatée ici
 *                                         (un remboursement « solde de tout compte ») ;
 *   remise                                partielle ou totale, avec les pénalités si le
 *                                         DG le décide ;
 *   accord amiable                        ce qui reste, en espèces à la Caisse, selon un
 *                                         nouvel échéancier ; le retard se compte depuis
 *                                         sa reprise.
 *
 * Une seule fois par dette : le règlement reste écrit sur la dette (`departure_terms`),
 * s'imprime en protocole d'accord et se trace dans l'audit.
 */
class SettleStaffDebtDepartureAction
{
    public function __construct(
        private readonly StaffDebtLedger $ledger,
        private readonly StaffDebtPenalties $penalties,
        private readonly StaffDebtNotifier $notifier,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{retained_amount?: ?string, retained_on?: ?string, write_off_amount?: ?string, waive_penalties?: bool,
     *               installment_amount?: ?string, first_period?: ?string, keep_penalties?: bool, note: string}  $data
     */
    public function execute(StaffDebt $debt, array $data, User $actor): StaffDebt
    {
        if ($actor->cannot('staff_debts.decide')) {
            throw new AuthorizationException('Seul le DG règle la dette d’une personne qui part.');
        }

        $retained = $this->amount($data['retained_amount'] ?? null, 'retained_amount');
        $writeOff = $this->amount($data['write_off_amount'] ?? null, 'write_off_amount');
        $waivePenalties = (bool) ($data['waive_penalties'] ?? false);

        if (($writeOff > 0 || $waivePenalties) && $actor->cannot('staff_debts.write_off')) {
            throw new AuthorizationException('Une remise demande le droit « staff_debts.write_off ».');
        }

        $note = Str::squish((string) ($data['note'] ?? ''));
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'Écrivez ce qui a été convenu avec la personne : il figure sur le protocole d’accord.']);
        }

        $debt = DB::transaction(function () use ($debt, $data, $actor, $retained, $writeOff, $waivePenalties, $note): StaffDebt {
            $debt = StaffDebt::query()->with(['employee', 'repayments', 'penalties'])->lockForUpdate()->findOrFail($debt->getKey());

            if (! $debt->awaitsDepartureSettlement()) {
                throw ValidationException::withMessages(['debt' => $debt->departure_settled_at !== null
                    ? 'Le départ de cette personne est déjà réglé.'
                    : 'Seule la dette en remboursement d’une personne qui a quitté la clinique se règle au départ.']);
            }

            $before = $debt->balanceMinor();
            $penaltiesWaived = $waivePenalties ? $this->penalties->waiveAllWithin($debt, 'Règlement au départ : '.$note, $actor) : 0;
            $balance = $before - $penaltiesWaived;
            // Un remboursement paie d'abord le montant et son intérêt : ce qui reste de pénalités est à part.
            $principalLeft = max(0, $debt->principalOwedMinor() - $debt->repaidMinor());

            if ($writeOff > $principalLeft) {
                throw ValidationException::withMessages(['write_off_amount' => 'La remise porte sur le montant et son intérêt : au plus '
                    .StaffDebtNotifier::money(Money::fromMinor($principalLeft)).'. Les pénalités se remettent à part.']);
            }

            if ($retained + $writeOff > $balance) {
                throw ValidationException::withMessages(['retained_amount' => 'La retenue et la remise dépassent le reste dû ('.StaffDebtNotifier::money(Money::fromMinor($balance)).').']);
            }

            $rest = $balance - $retained - $writeOff;
            $retainedOn = $retained > 0 ? $this->retainedOn($data['retained_on'] ?? null, $debt) : null;
            [$installment, $first] = $rest > 0 ? $this->schedule($data, $rest) : [null, null];
            $keepPenalties = $rest > 0 && ($data['keep_penalties'] ?? true) && $debt->penaltyRule() !== null;

            if ($retained > 0) {
                $debt->repayments()->create([
                    'employee_id' => $debt->employee_id,
                    'source' => StaffDebtRepaymentSource::FinalPay,
                    'period' => $retainedOn->copy()->startOfMonth()->toDateString(),
                    'amount' => Money::fromMinor($retained),
                    'note' => 'Retenue sur le solde de tout compte du '.$retainedOn->format('d/m/Y').' — règlement au départ.',
                    'recorded_at' => now(),
                    'recorded_by' => $actor->getKey(),
                    ...RemoteActorAttribution::fields('recorded', $actor),
                ]);
                $debt->unsetRelation('repayments');
            }

            $plan = $rest > 0 ? StaffDebtLedger::plan($rest, $installment, $first) : null;
            $terms = [
                'balance_before' => Money::fromMinor($before),
                'penalties_waived' => Money::fromMinor($penaltiesWaived),
                'retained' => Money::fromMinor($retained),
                'retained_on' => $retainedOn?->toDateString(),
                'written_off' => Money::fromMinor($writeOff),
                'rest' => Money::fromMinor($rest),
                'installment' => $installment !== null ? Money::fromMinor($installment) : null,
                'first_period' => $first?->format('Y-m'),
                'last_period' => $plan['last_period'] ?? null,
                'count' => $plan['count'] ?? null,
                'penalties_continue' => $keepPenalties,
                'note' => $note,
            ];

            $debt->forceFill([
                'written_off_amount' => Money::fromMinor(Money::toMinor((string) $debt->written_off_amount) + $writeOff),
                'departure_settled_at' => now(),
                'departure_settled_by' => $actor->getKey(),
                'departure_terms' => $terms,
                ...RemoteActorAttribution::fields('departure_settled', $actor),
            ]);

            if ($rest > 0) {
                $debt->forceFill([
                    'installment_amount' => Money::fromMinor($installment),
                    'first_period' => $first->toDateString(),
                    'repayment_mode' => StaffDebtRepaymentMode::Cash,
                    // Le retard de l'accord amiable se compte depuis sa reprise.
                    'schedule_offset' => Money::fromMinor($debt->repaidMinor()),
                    'arrears_notified_for' => null,
                    ...($keepPenalties ? [] : ['penalty_rate' => null, 'penalty_grace_days' => null, 'penalty_cap_rate' => null]),
                ]);
            } elseif ($writeOff > 0) {
                $debt->forceFill([
                    'status' => StaffDebtStatus::WrittenOff,
                    'write_off_reason' => 'Règlement au départ : '.$note,
                    'written_off_at' => now(),
                    'written_off_by' => $actor->getKey(),
                    ...RemoteActorAttribution::fields('written_off', $actor),
                ]);
            }

            $debt->save();

            if ($rest === 0 && $writeOff === 0) {
                $this->ledger->refreshStatus($debt);
            }

            $this->auditor->record('staff_debt.departure_settle', entity: $debt, newValues: $terms, reason: $note, module: 'finance', actor: $actor);

            return $debt;
        });

        $terms = $debt->departure_terms;
        $this->notifier->employee(
            $debt,
            'departure_settled',
            'Votre dette '.$debt->number.' est réglée au départ',
            (Money::toMinor($terms['rest']) > 0
                ? 'Reste '.StaffDebtNotifier::money($terms['rest']).' à remettre à la Caisse : '.$terms['count'].' mensualité'.($terms['count'] > 1 ? 's' : '')
                    .' de '.StaffDebtNotifier::money($terms['installment']).' à partir de '.Carbon::createFromFormat('Y-m-d', $terms['first_period'].'-01')->translatedFormat('F Y').'.'
                : 'Plus rien n’est dû.'),
        );

        return $debt;
    }

    private function amount(mixed $value, string $field): int
    {
        if (blank($value)) {
            return 0;
        }

        $text = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $value));
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            throw ValidationException::withMessages([$field => 'Ce montant ne se lit pas.']);
        }

        return Money::toMinor($text);
    }

    private function retainedOn(mixed $value, StaffDebt $debt): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages(['retained_on' => 'Indiquez la date du solde de tout compte.']);
        }

        $on = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        if ($on->isAfter(now()->startOfDay())) {
            throw ValidationException::withMessages(['retained_on' => 'Une retenue se constate une fois faite : la date ne peut pas être à venir.']);
        }

        if ($debt->disbursed_on !== null && $on->lt($debt->disbursed_on)) {
            throw ValidationException::withMessages(['retained_on' => 'La retenue ne peut pas précéder le versement du '.$debt->disbursed_on->format('d/m/Y').'.']);
        }

        return $on;
    }

    /** @return array{0: int, 1: Carbon} */
    private function schedule(array $data, int $rest): array
    {
        $installment = $this->amount($data['installment_amount'] ?? null, 'installment_amount');
        $first = StaffDebtTerms::period($data['first_period'] ?? null, 'first_period');
        StaffDebtTerms::assert($rest, $installment, $first, 'retained_amount', 'installment_amount', 'first_period');

        return [$installment, $first];
    }
}
