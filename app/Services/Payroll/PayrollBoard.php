<?php

namespace App\Services\Payroll;

use App\Enums\AdvantageEntryStatus;
use App\Enums\BonusAwardStatus;
use App\Enums\EmployeeBenefitFrequency;
use App\Enums\SalaryPaymentStatus;
use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\AdvantageAward;
use App\Models\AdvantageEntry;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
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
 * et ses avantages du mois — saisis (ADR-227), déclarés sur la fiche (ADR-221), à l'acte
 * validés (ADR-226) — font le brut. Les dettes du personnel à retenue sur salaire (ADR-228)
 * s'en retranchent, en lignes négatives `DEBT`, sans jamais dépasser le brut : à verser =
 * brut − retenues. Aucune cotisation ni impôt n'est calculé (ADR-066). Une paie marquée
 * payée montre ses lignes figées. Le tableau lit ; payer recompte.
 */
class PayrollBoard
{
    public function __construct(private readonly StaffDebtLedger $ledger) {}

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>} */
    public function month(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $payments = SalaryPayment::query()->whereDate('period', $month->toDateString())
            ->with(['payer:id,name', 'canceller:id,name'])->latest('id')->get();
        $entries = AdvantageEntry::query()->whereDate('period', $month->toDateString())
            ->where('status', AdvantageEntryStatus::Pending)->orderBy('id')->get()->groupBy('employee_id');
        $awards = AdvantageAward::query()->whereDate('period', $month->toDateString())
            ->where('status', BonusAwardStatus::Validated)->whereNotNull('employee_id')->get()->keyBy('employee_id');
        $benefits = $this->declaredBenefits($month)->groupBy('employee_id');
        $debts = $this->salaryDebts()->groupBy('employee_id');

        $ids = Employee::query()->where('active', true)
            ->where(fn ($query) => $query->where('remuneration_amount', '>', 0))
            ->pluck('id')
            ->merge($entries->keys())->merge($awards->keys())->merge($benefits->keys())
            ->merge($payments->pluck('employee_id'))
            ->unique();

        $employees = Employee::withTrashed()->with('jobTitle:id,label')->whereKey($ids->all())->get();

        $rows = $employees->map(function (Employee $employee) use ($month, $payments, $entries, $awards, $benefits, $debts) {
            $mine = $payments->where('employee_id', $employee->getKey());
            $payment = $mine->first(fn (SalaryPayment $payment) => $payment->status === SalaryPaymentStatus::Paid);
            $lines = $payment ? $payment->lines : $this->lines(
                $employee,
                $entries->get($employee->getKey(), collect()),
                $benefits->get($employee->getKey(), collect()),
                $awards->get($employee->getKey()),
            );
            if ($payment === null) {
                $lines = [...$lines, ...$this->debtLines($debts->get($employee->getKey(), collect()), $month, $lines)];
            }
            $gross = self::grossOf($lines);
            $deductions = self::deductionsOf($lines);
            $total = $gross - $deductions;

            return [
                'uuid' => $employee->uuid,
                'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'employee_number' => $employee->employee_number,
                'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                'remuneration_label' => $employee->remuneration_type?->label(),
                'in_post' => $employee->active && ! $employee->trashed(),
                'lines' => array_values($lines),
                'base_amount' => $this->money(array_sum(array_map(fn ($line) => $line['kind'] === 'BASE' ? (float) $line['amount'] : 0, $lines))),
                'advantages_amount' => $this->money(array_sum(array_map(fn ($line) => ! in_array($line['kind'], ['BASE', 'DEBT'], true) ? (float) $line['amount'] : 0, $lines))),
                'gross' => $this->money($gross),
                'deductions_amount' => $this->money($deductions),
                // À verser : le brut moins les retenues (ADR-228).
                'total' => $this->money($total),
                'payment' => $this->payment($payment),
                'cancelled' => $mine->where('status', SalaryPaymentStatus::Cancelled)->values()->map(fn ($payment) => $this->payment($payment))->all(),
                'payable' => $payment === null && $gross > 0 && ! $month->isAfter(now()->startOfMonth()),
            ];
        })->filter(fn (array $row) => $row['payment'] !== null || (float) $row['gross'] > 0 || $row['cancelled'] !== [])
            ->sortBy('name')->values();

        $paid = $payments->where('status', SalaryPaymentStatus::Paid);

        return [
            'rows' => $rows->all(),
            'summary' => [
                'people' => $rows->count(),
                'to_pay' => $rows->where('payable', true)->count(),
                'paid' => $paid->count(),
                'amount_to_pay' => $this->money($rows->where('payable', true)->sum(fn ($row) => (float) $row['total'])),
                'amount_paid' => $this->money($paid->sum(fn (SalaryPayment $payment) => (float) $payment->total_amount - (float) $payment->deductions_amount)),
                'advantages_to_pay' => $this->money($rows->where('payable', true)->sum(fn ($row) => (float) $row['advantages_amount'])),
                'deductions_to_pay' => $this->money($rows->where('payable', true)->sum(fn ($row) => (float) $row['deductions_amount'])),
            ],
        ];
    }

    /**
     * ADR-228 — les retenues de dettes d'un employé pour un mois, calculées sur ses
     * lignes de brut : jamais plus que ce brut, la plus ancienne dette d'abord.
     *
     * @param  Collection<int, StaffDebt>  $debts
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{kind: string, label: string, amount: string, uuid: string}>
     */
    public function debtLines(Collection $debts, Carbon $month, array $lines): array
    {
        if ($debts->isEmpty()) {
            return [];
        }

        $grossMinor = Money::toMinor($this->money(self::grossOf($lines)));

        return array_map(
            fn (array $deduction) => $this->ledger->payrollLine($deduction['debt'], $deduction['amount_minor'], $deduction['due_minor']),
            $this->ledger->salaryDeductions($debts, $month, $grossMinor),
        );
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
        return array_sum(array_map(fn ($line) => ($line['kind'] ?? null) === 'DEBT' ? 0 : (float) $line['amount'], $lines));
    }

    /** @param  list<array<string, mixed>>  $lines  les retenues, en valeur positive */
    public static function deductionsOf(array $lines): float
    {
        return -array_sum(array_map(fn ($line) => ($line['kind'] ?? null) === 'DEBT' ? (float) $line['amount'] : 0, $lines));
    }

    /**
     * Les lignes de la paie d'un employé pour un mois, telles que payer les figera.
     *
     * @return list<array{kind: string, label: string, amount: string, uuid?: string|null}>
     */
    public function lines(Employee $employee, Collection $entries, Collection $benefits, ?AdvantageAward $award): array
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

        if ($award !== null && (float) $award->total_amount > 0) {
            $lines[] = ['kind' => 'ACTS', 'label' => 'Avantages à l’acte (validés)', 'amount' => $this->money($award->total_amount), 'uuid' => $award->uuid];
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
