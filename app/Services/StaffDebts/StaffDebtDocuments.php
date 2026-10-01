<?php

namespace App\Services\StaffDebts;

use App\Enums\StaffDebtRepaymentSource;
use App\Models\StaffDebt;
use App\Services\Settings\AppSettings;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * ADR-230 — les deux documents imprimables d'une dette du personnel :
 *
 *   reconnaissance de dette   ce que la personne a reçu et s'engage à rembourser, avec
 *                             l'échéancier, l'intérêt et la règle de pénalité figés à l'accord
 *   protocole d'accord        ce qui a été convenu à son départ : retenue sur la dernière
 *                             paie, remise, pénalités remises, reste et son échéancier
 *
 * Rien n'est recalculé à l'écran : ce service lit la dette telle qu'elle est enregistrée,
 * le document ne fait que l'imprimer. Les deux documents se signent à la main — RIVO
 * n'appose aucune signature d'office.
 */
class StaffDebtDocuments
{
    public function __construct(private readonly AppSettings $settings) {}

    /** @return array<string, mixed> */
    public function acknowledgement(StaffDebt $debt): array
    {
        abort_if($debt->amount === null, 404, 'Une dette qui n’a pas été accordée n’a pas de reconnaissance.');

        $debt->loadMissing(['employee.jobTitle:id,label', 'employee.department:id,label', 'repayments', 'penalties', 'decider']);
        $rule = $debt->penaltyRule();

        return [
            'kind' => 'ACKNOWLEDGEMENT',
            'title' => 'Reconnaissance de dette',
            ...$this->common($debt),
            'terms' => [
                'amount' => (string) $debt->amount,
                'interest_amount' => (string) ($debt->interest_amount ?? '0'),
                'interest_waived' => (bool) $debt->interest_waived,
                'total' => Money::fromMinor($debt->totalDueMinor()),
                'installment_amount' => (string) $debt->installment_amount,
                'first_period' => $debt->first_period?->format('Y-m'),
                'repayment_mode' => $debt->repayment_mode?->value,
                'repayment_mode_label' => $debt->repayment_mode?->label(),
                'plan' => $debt->first_period !== null
                    ? StaffDebtLedger::plan($debt->totalDueMinor(), Money::toMinor((string) $debt->installment_amount), $debt->first_period)
                    : null,
                'reason' => $debt->reason,
                'decided_at' => $debt->decided_at?->toDateString(),
                'decided_by' => RemoteActorAttribution::name($debt->decider?->name, $debt->external_decided_by_name),
                'disbursed_on' => $debt->disbursed_on?->toDateString(),
                'disbursement_mode_label' => $debt->disbursement_mode?->label(),
                'disbursement_reference' => $debt->disbursement_reference,
            ],
            'penalty' => $rule === null ? null : [
                'rate' => StaffDebtPenalties::rate($rule['rate']),
                'grace_days' => $rule['grace_days'],
                'cap_rate' => $rule['cap_rate'] !== null ? StaffDebtPenalties::rate($rule['cap_rate']) : null,
                'cap' => $rule['cap_rate'] !== null ? Money::fromMinor(Money::percentage(Money::toMinor((string) $debt->amount), $rule['cap_rate'])) : null,
                'applies' => $debt->repayment_mode?->value === 'CASH',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function departureAgreement(StaffDebt $debt): array
    {
        abort_if($debt->departure_settled_at === null, 404, 'Aucun règlement au départ n’a été convenu pour cette dette.');

        $debt->loadMissing(['employee.jobTitle:id,label', 'employee.department:id,label', 'repayments', 'penalties', 'departureSettler', 'decider']);
        $terms = $debt->departure_terms ?? [];

        return [
            'kind' => 'DEPARTURE_AGREEMENT',
            'title' => 'Protocole d’accord — règlement de dette au départ',
            ...$this->common($debt),
            'terms' => [
                'amount' => (string) $debt->amount,
                'total' => Money::fromMinor($debt->totalDueMinor()),
                'disbursed_on' => $debt->disbursed_on?->toDateString(),
                'repaid_before' => $this->repaidBefore($debt),
            ],
            'departure' => [
                'settled_at' => $debt->departure_settled_at->toDateString(),
                'settled_by' => RemoteActorAttribution::name($debt->departureSettler?->name, $debt->external_departure_settled_by_name),
                'left_on' => $debt->employee?->deleted_at?->toDateString(),
                'balance_before' => $terms['balance_before'] ?? null,
                'penalties_waived' => $terms['penalties_waived'] ?? '0.00',
                'retained' => $terms['retained'] ?? '0.00',
                'retained_on' => $terms['retained_on'] ?? null,
                'written_off' => $terms['written_off'] ?? '0.00',
                'rest' => $terms['rest'] ?? '0.00',
                'installment' => $terms['installment'] ?? null,
                'first_period' => $terms['first_period'] ?? null,
                'last_period' => $terms['last_period'] ?? null,
                'count' => $terms['count'] ?? 0,
                'penalties_continue' => (bool) ($terms['penalties_continue'] ?? false),
                'penalty_rate' => $debt->penaltyRule() !== null ? StaffDebtPenalties::rate($debt->penaltyRule()['rate']) : null,
                'note' => $terms['note'] ?? null,
            ],
        ];
    }

    /** Ce qui avait déjà été remboursé avant le départ, lu sur les remboursements non annulés antérieurs au règlement. */
    private function repaidBefore(StaffDebt $debt): string
    {
        $settledAt = $debt->departure_settled_at;
        $minor = (int) $debt->repayments
            ->whereNull('reversed_at')
            ->filter(fn ($repayment) => $repayment->recorded_at !== null && $repayment->recorded_at->lt($settledAt)
                && $repayment->source !== StaffDebtRepaymentSource::FinalPay)
            ->sum(fn ($repayment) => Money::toMinor((string) $repayment->amount));

        return Money::fromMinor($minor);
    }

    /** L'établissement, la personne et la dette : l'en-tête commun aux deux documents. @return array<string, mixed> */
    private function common(StaffDebt $debt): array
    {
        $employee = $debt->employee;
        $director = $this->settings->director();
        $documents = $this->settings->documents();

        return [
            'debt' => [
                'uuid' => $debt->uuid,
                'number' => $debt->number,
                'status' => $debt->status->value,
                'status_label' => $debt->status->label(),
                'balance' => Money::fromMinor($debt->balanceMinor()),
            ],
            'employee' => [
                'name' => $debt->employee_name ?: Str::squish(($employee?->last_name ?? '').' '.($employee?->first_name ?? '')),
                'employee_number' => $debt->employee_number ?? $employee?->employee_number,
                'job_title' => $employee?->jobTitle?->label ?? $employee?->profession,
                'department' => $employee?->department?->label,
                'identity_document_number' => $employee?->identity_document_number,
                'identity_document_issued_on' => $employee?->identity_document_issued_on?->toDateString(),
                'hired_on' => $employee?->hire_date?->toDateString(),
            ],
            'establishment' => [
                'name' => $this->settings->brand(),
                'site' => config('rivo.site.name'),
                'address' => $documents['address'] ?? null,
                'director_name' => $director['name'],
                'director_title' => $director['title'],
            ],
            'printed_on' => Carbon::today()->toDateString(),
        ];
    }
}
