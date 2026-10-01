<?php

namespace App\Services\Payroll;

use App\Enums\AdvantageEntryStatus;
use App\Enums\EmployeeBenefitFrequency;
use App\Enums\MobileMoneyOperator;
use App\Enums\SalaryPaymentMode;
use App\Enums\SalaryPaymentStatus;
use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\AdvantageEntry;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\PayrollSetting;
use App\Models\SalaryPayment;
use App\Models\StaffDebt;
use App\Services\StaffDebts\StaffDebtLedger;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ADR-227 — la paie d'un mois : pour chaque employé, le salaire de base déclaré (ADR-206)
 * et ses avantages du mois — saisis (ADR-227) et déclarés sur la fiche (ADR-221) — font
 * le brut. ADR-233 — les retenues légales (CNAPS, organisme médical, IRSA) se calculent sur
 * ce brut selon les paramètres de paie du site, s'ils sont activés ; les charges patronales
 * s'affichent pour information. ADR-228 — les dettes à retenue sur salaire s'en retranchent
 * ensuite, sans jamais dépasser ce qui reste : net à verser = brut − retenues légales −
 * dettes. Une paie marquée payée montre ses lignes, ses paramètres et son mode de paiement
 * figés. Le tableau lit ; payer recompte avec `draft()`, le même calcul.
 */
class PayrollBoard
{
    public const LEGAL_KINDS = PayrollCalculator::DEDUCTION_KINDS;

    public const DEDUCTION_KINDS = [...PayrollCalculator::DEDUCTION_KINDS, 'DEBT'];

    public function __construct(
        private readonly StaffDebtLedger $ledger,
        private readonly PayrollCalculator $calculator,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>, settings: array<string, mixed>} */
    public function month(Carbon $month, ?array $only = null): array
    {
        $month = $month->copy()->startOfMonth();
        $settings = PayrollSetting::current();
        $payments = SalaryPayment::query()->whereDate('period', $month->toDateString())
            ->with(['payer:id,name', 'canceller:id,name'])->latest('id')->get();
        $entries = AdvantageEntry::query()->whereDate('period', $month->toDateString())
            ->where('status', AdvantageEntryStatus::Pending)->orderBy('id')->get()->groupBy('employee_id');
        $benefits = $this->declaredBenefits($month)->groupBy('employee_id');
        $debts = $this->salaryDebts()->groupBy('employee_id');

        $ids = Employee::query()->where('active', true)
            ->where(fn ($query) => $query->where('remuneration_amount', '>', 0))
            ->pluck('id')
            ->merge($entries->keys())->merge($benefits->keys())
            ->merge($payments->pluck('employee_id'))
            ->unique();

        $employees = Employee::withTrashed()
            ->with(['jobTitle:id,label', 'department:id,label', 'bank:id,code,name,bank_code'])
            ->whereKey($ids->all())
            ->when($only !== null, fn ($query) => $query->whereIn('uuid', $only))
            ->get();

        $rows = $employees->map(function (Employee $employee) use ($month, $payments, $entries, $benefits, $debts, $settings) {
            $mine = $payments->where('employee_id', $employee->getKey());
            $payment = $mine->first(fn (SalaryPayment $payment) => $payment->status === SalaryPaymentStatus::Paid);

            if ($payment !== null) {
                $lines = $payment->lines;
                $snapshot = $payment->payroll_snapshot ?? [];
                $employer = $snapshot['employer'] ?? [];
                $legal = $snapshot['legal'] ?? null;
                $paymentMode = $this->paymentModeOf($payment->payment_mode, $payment->payment_details);
            } else {
                $draft = $this->draft(
                    $employee,
                    $month,
                    $entries->get($employee->getKey(), collect()),
                    $benefits->get($employee->getKey(), collect()),
                    $debts->get($employee->getKey(), collect()),
                    $settings,
                );
                $lines = $draft['lines'];
                $employer = $draft['employer'];
                $legal = $draft['legal'];
                $paymentMode = $this->paymentModeOf($draft['payment_mode'], $draft['payment_details']);
            }

            $gross = self::grossOf($lines);
            $legalTotal = self::legalOf($lines);
            $debtsTotal = self::debtsOf($lines);
            $employerTotal = array_sum(array_map(fn ($line) => (float) $line['amount'], $employer));
            $net = $gross - $legalTotal - $debtsTotal;

            return [
                'uuid' => $employee->uuid,
                'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'employee_number' => $employee->employee_number,
                'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                'department' => $employee->department?->label,
                'hire_date' => $employee->hire_date?->toDateString(),
                'children' => (int) ($employee->children_count ?? 0),
                'remuneration_label' => $employee->remuneration_type?->label(),
                'in_post' => $employee->active && ! $employee->trashed(),
                'lines' => array_values($lines),
                'employer_lines' => array_values($employer),
                'legal' => $legal === null ? null : [
                    'applies' => (bool) ($legal['applies'] ?? false),
                    'reason' => $legal['reason'] ?? null,
                    'taxable' => isset($legal['taxable']) ? Money::fromMinor((int) $legal['taxable']) : null,
                ],
                'base_amount' => $this->money(array_sum(array_map(fn ($line) => $line['kind'] === 'BASE' ? (float) $line['amount'] : 0, $lines))),
                'advantages_amount' => $this->money(array_sum(array_map(fn ($line) => ! in_array($line['kind'], ['BASE', ...self::DEDUCTION_KINDS], true) ? (float) $line['amount'] : 0, $lines))),
                'gross' => $this->money($gross),
                'legal_amount' => $this->money($legalTotal),
                'debts_amount' => $this->money($debtsTotal),
                // Toutes les retenues : légales et dettes.
                'deductions_amount' => $this->money($legalTotal + $debtsTotal),
                // Net à verser : brut − retenues légales − dettes.
                'total' => $this->money($net),
                'employer_amount' => $this->money($employerTotal),
                'cost' => $this->money($gross + $employerTotal),
                'payment_mode' => $paymentMode,
                'payment' => $this->payment($payment),
                'cancelled' => $mine->where('status', SalaryPaymentStatus::Cancelled)->values()->map(fn ($payment) => $this->payment($payment))->all(),
                'payable' => $payment === null && $gross > 0 && ! $month->isAfter(now()->startOfMonth()),
            ];
        })->filter(fn (array $row) => $row['payment'] !== null || (float) $row['gross'] > 0 || $row['cancelled'] !== [])
            ->sortBy('name')->values();

        $toPay = $rows->where('payable', true);
        $paidRows = $rows->filter(fn ($row) => $row['payment'] !== null);
        $sum = fn (Collection $set, string $key) => $this->money($set->sum(fn ($row) => (float) $row[$key]));

        return [
            'rows' => $rows->all(),
            'summary' => [
                'people' => $rows->count(),
                'to_pay' => $toPay->count(),
                'paid' => $paidRows->count(),
                'amount_to_pay' => $sum($toPay, 'total'),
                'amount_paid' => $sum($paidRows, 'total'),
                'gross_to_pay' => $sum($toPay, 'gross'),
                'advantages_to_pay' => $sum($toPay, 'advantages_amount'),
                'legal_to_pay' => $sum($toPay, 'legal_amount'),
                'deductions_to_pay' => $sum($toPay, 'debts_amount'),
                'employer_to_pay' => $sum($toPay, 'employer_amount'),
                'gross_month' => $sum($rows->filter(fn ($row) => $row['payable'] || $row['payment'] !== null), 'gross'),
                'cost_month' => $sum($rows->filter(fn ($row) => $row['payable'] || $row['payment'] !== null), 'cost'),
            ],
            'settings' => [
                'legal_enabled' => (bool) $settings->legal_deductions_enabled,
                'configured' => $settings->exists,
                'health_label' => $settings->healthLabel(),
            ],
        ];
    }

    /**
     * Le calcul d'une paie non payée, le même pour le tableau et pour « Marquer payé » :
     * lignes du brut, retenues légales, retenues de dettes (sur ce qui reste après les
     * retenues légales), charges patronales, paramètres utilisés et mode de paiement.
     *
     * @param  Collection<int, StaffDebt>  $debts
     * @return array{lines: list<array<string, mixed>>, debt_deductions: list<array<string, mixed>>, employer: list<array<string, mixed>>, legal: array<string, mixed>, snapshot: array<string, mixed>, payment_mode: ?string, payment_details: ?array<string, mixed>}
     */
    public function draft(Employee $employee, Carbon $month, Collection $entries, Collection $benefits, Collection $debts, PayrollSetting $settings): array
    {
        $rules = $settings->snapshot();
        $lines = $this->lines($employee, $entries, $benefits);
        $grossMinor = Money::toMinor($this->money(self::grossOf($lines)));

        $legal = $this->calculator->compute($grossMinor, $employee->remuneration_type, (int) ($employee->children_count ?? 0), $rules);
        $lines = [...$lines, ...$this->calculator->lines($legal, $rules)];
        $employer = $this->calculator->employerLines($legal, $rules);

        $available = max(0, $grossMinor - $legal['cnaps'] - $legal['health'] - $legal['irsa']);
        $debtDeductions = $debts->isEmpty() ? [] : $this->ledger->salaryDeductions($debts, $month, $available);
        foreach ($debtDeductions as $deduction) {
            $lines[] = $this->ledger->payrollLine($deduction['debt'], $deduction['amount_minor'], $deduction['due_minor']);
        }

        [$mode, $details] = $this->paymentDetails($employee);

        return [
            'lines' => $lines,
            'debt_deductions' => $debtDeductions,
            'employer' => $employer,
            'legal' => $legal,
            'snapshot' => ['rules' => $rules, 'legal' => $legal, 'employer' => $employer],
            'payment_mode' => $mode,
            'payment_details' => $details,
        ];
    }

    /**
     * Comment la personne est payée, repris de sa fiche (ADR-225) : virement (banque,
     * compte), Mobile Money (comptes) ou espèces. Figé sur la paie au paiement.
     *
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    public function paymentDetails(Employee $employee): array
    {
        $mode = $employee->salary_payment_mode;
        if ($mode === null) {
            return [null, null];
        }

        return [$mode->value, match ($mode) {
            SalaryPaymentMode::Bank => [
                'bank' => $employee->bank?->code ?? null,
                'bank_name' => $employee->bank?->name ?? null,
                'bank_code' => $employee->bank?->bank_code ?? null,
                'account_number' => $employee->bank_account_number,
                'account_holder' => $employee->bank_account_holder,
            ],
            SalaryPaymentMode::MobileMoney => [
                'accounts' => array_values(array_map(fn (array $account) => [
                    'operator' => MobileMoneyOperator::tryFrom((string) ($account['operator'] ?? ''))?->label() ?? ($account['operator'] ?? null),
                    'number' => $account['number'] ?? null,
                    'holder' => $account['holder'] ?? null,
                ], is_array($employee->mobile_money_accounts) ? $employee->mobile_money_accounts : [])),
            ],
            SalaryPaymentMode::Cash => [],
        }];
    }

    /** @return array{mode: ?string, label: string, details: array<string, mixed>, summary: string} */
    private function paymentModeOf(?string $mode, ?array $details): array
    {
        $enum = $mode !== null ? SalaryPaymentMode::tryFrom($mode) : null;
        $details ??= [];
        $summary = match ($enum) {
            SalaryPaymentMode::Bank => implode(' · ', array_filter([$details['bank'] ?? $details['bank_name'] ?? null, $details['account_number'] ?? null])) ?: 'Compte non renseigné',
            SalaryPaymentMode::MobileMoney => implode(' · ', array_filter(array_map(fn ($account) => trim(($account['operator'] ?? '').' '.($account['number'] ?? '')), $details['accounts'] ?? []))) ?: 'Numéro non renseigné',
            SalaryPaymentMode::Cash => 'À la caisse',
            default => 'À renseigner sur la fiche (étape Banque)',
        };

        return ['mode' => $enum?->value, 'label' => $enum?->label() ?? 'Mode non renseigné', 'details' => $details, 'summary' => $summary];
    }

    /**
     * Les dettes versées, à retenue sur salaire, remboursements chargés : ce que la paie peut retenir.
     *
     * @return Collection<int, StaffDebt>
     */
    public function salaryDebts(?int $employeeId = null, bool $lock = false): Collection
    {
        return StaffDebt::query()
            ->where('status', StaffDebtStatus::Active->value)
            ->where('repayment_mode', StaffDebtRepaymentMode::Salary->value)
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->with(['repayments', 'penalties'])
            ->orderBy('id')
            ->get();
    }

    /** @param  list<array<string, mixed>>  $lines */
    public static function grossOf(array $lines): float
    {
        return array_sum(array_map(fn ($line) => in_array($line['kind'] ?? null, self::DEDUCTION_KINDS, true) ? 0 : (float) $line['amount'], $lines));
    }

    /** @param  list<array<string, mixed>>  $lines  toutes les retenues (légales et dettes), en valeur positive */
    public static function deductionsOf(array $lines): float
    {
        return self::legalOf($lines) + self::debtsOf($lines);
    }

    /** @param  list<array<string, mixed>>  $lines  les retenues légales (CNAPS, organisme médical, IRSA), en valeur positive */
    public static function legalOf(array $lines): float
    {
        return -array_sum(array_map(fn ($line) => in_array($line['kind'] ?? null, self::LEGAL_KINDS, true) ? (float) $line['amount'] : 0, $lines));
    }

    /** @param  list<array<string, mixed>>  $lines  les retenues de dettes (ADR-228), en valeur positive */
    public static function debtsOf(array $lines): float
    {
        return -array_sum(array_map(fn ($line) => ($line['kind'] ?? null) === 'DEBT' ? (float) $line['amount'] : 0, $lines));
    }

    /**
     * Les lignes de la paie d'un employé pour un mois, telles que payer les figera.
     *
     * @return list<array{kind: string, label: string, amount: string, uuid?: string|null}>
     */
    public function lines(Employee $employee, Collection $entries, Collection $benefits): array
    {
        $lines = [];

        if ($employee->remuneration_type?->hasAmount() && (float) $employee->remuneration_amount > 0) {
            $lines[] = ['kind' => 'BASE', 'label' => $employee->remuneration_type->label(), 'amount' => $this->money($employee->remuneration_amount), 'uuid' => null];
        }

        foreach ($benefits as $benefit) {
            $lines[] = [
                'kind' => 'DECLARED',
                'label' => Str::squish(($benefit->benefitType?->label ?? 'Avantage').' — '.$benefit->reason),
                'amount' => $this->money($benefit->amount),
                'uuid' => $benefit->uuid,
            ];
        }

        foreach ($entries as $entry) {
            $lines[] = ['kind' => 'ENTRY', 'label' => $entry->reason, 'amount' => $this->money($entry->amount), 'uuid' => $entry->uuid];
        }

        return $lines;
    }

    /** Les avantages déclarés sur la fiche (ADR-221) qui valent pour ce mois, avec un montant. */
    public function declaredBenefits(Carbon $month, ?int $employeeId = null): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return EmployeeBenefit::query()
            ->with('benefitType:id,label')
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
            ->whereNotNull('amount')->where('amount', '>', 0)
            ->where(function ($query) use ($start, $end) {
                $query->where(fn ($q) => $q->where('frequency', EmployeeBenefitFrequency::Monthly->value)
                    ->whereDate('starts_on', '<=', $end->toDateString())
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString())))
                    ->orWhere(fn ($q) => $q->where('frequency', EmployeeBenefitFrequency::OneTime->value)
                        ->whereDate('starts_on', '>=', $start->toDateString())
                        ->whereDate('starts_on', '<=', $end->toDateString()));
            })
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed>|null */
    private function payment(?SalaryPayment $payment): ?array
    {
        if ($payment === null) {
            return null;
        }

        return [
            'uuid' => $payment->uuid,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            // À verser, retenues déduites ; le brut et les retenues à côté.
            'total' => $this->money((float) $payment->total_amount - (float) $payment->deductions_amount),
            'gross' => (string) $payment->total_amount,
            'deductions' => (string) $payment->deductions_amount,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'paid_by' => RemoteActorAttribution::name($payment->payer?->name, $payment->external_paid_by_name),
            'payment_note' => $payment->payment_note,
            'cancelled_at' => $payment->cancelled_at?->toIso8601String(),
            'cancelled_by' => RemoteActorAttribution::name($payment->canceller?->name, $payment->external_cancelled_by_name),
            'cancel_reason' => $payment->cancel_reason,
        ];
    }

    private function money(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
