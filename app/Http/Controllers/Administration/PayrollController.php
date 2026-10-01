<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Payroll\PaySalaryAction;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Services\Audit\Auditor;
use App\Services\Payroll\PayrollBoard;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Support\Hr\Seniority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * ADR-227 / ADR-233 — la paie du mois, une rubrique RH (servie aussi au portail, ADR-187) :
 * brut − retenues légales − dettes = net à verser. Marquer payé (un salarié ou une
 * sélection) fige la paie ; le virement se fait hors RIVO. Bulletins imprimables, journal
 * de paie et liste de virement en Excel.
 */
class PayrollController extends Controller
{
    private const BULK_LIMIT = 100;

    public function index(Request $request, PayrollBoard $board): Response
    {
        $month = $this->month($request->query('mois')) ?? now()->startOfMonth();

        return Inertia::render('Administration/Payroll/Index', [
            'month' => $month->format('Y-m'),
            'currentMonth' => now()->format('Y-m'),
            'board' => $board->month($month),
            'bulkLimit' => self::BULK_LIMIT,
        ]);
    }

    public function pay(Request $request, PaySalaryAction $action): RedirectResponse
    {
        $data = $request->validate([
            'employee_uuid' => ['required', 'uuid'],
            'mois' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $employee = Employee::withTrashed()->where('uuid', $data['employee_uuid'])->firstOrFail();

        $payment = $action->execute($employee, $this->month($data['mois']), $data['note'] ?? null, $request->user());

        return back()->with('status', "Paie de {$payment->employee_name} marquée payée : ".$this->ariary($payment->netAmount()).' à verser.');
    }

    /**
     * Marquer payée une sélection : chaque salarié est recompté et figé séparément, par la
     * même action qu'un seul. Un refus n'empêche pas les autres ; le rapport dit pourquoi.
     */
    public function payBatch(Request $request, PaySalaryAction $action, Auditor $auditor): RedirectResponse
    {
        $data = $request->validate([
            'employee_uuids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'employee_uuids.*' => ['required', 'uuid', 'distinct'],
            'mois' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $month = $this->month($data['mois']);
        $employees = Employee::withTrashed()->whereIn('uuid', $data['employee_uuids'])->orderBy('last_name')->orderBy('first_name')->get();

        $report = ['action' => 'payroll_pay', 'total' => count($data['employee_uuids']), 'done' => 0, 'amount' => 0.0, 'failed' => []];
        foreach ($employees as $employee) {
            $label = trim("{$employee->last_name} {$employee->first_name}");
            try {
                $payment = $action->execute($employee, $month, $data['note'] ?? null, $request->user());
                $report['done']++;
                $report['amount'] += (float) $payment->netAmount();
            } catch (ValidationException $exception) {
                $report['failed'][] = ['label' => $label, 'message' => collect($exception->errors())->flatten()->first() ?? 'Refusé.'];
            } catch (AuthorizationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                $report['failed'][] = ['label' => $label, 'message' => 'Erreur inattendue : la paie n’a pas été marquée payée.'];
            }
        }
        foreach (array_diff($data['employee_uuids'], $employees->pluck('uuid')->all()) as $missing) {
            $report['failed'][] = ['label' => $missing, 'message' => 'Salarié introuvable.'];
        }
        $report['amount'] = number_format($report['amount'], 2, '.', '');

        $auditor->record('payroll.bulk_pay', null, ['period' => $month->format('Y-m'), 'done' => $report['done'], 'total' => $report['total'], 'amount' => $report['amount']], module: 'payroll');

        $failed = count($report['failed']);

        return back()
            ->with('status', $failed === 0
                ? "{$report['done']} paie".($report['done'] > 1 ? 's' : '').' marquée'.($report['done'] > 1 ? 's' : '').' payée'.($report['done'] > 1 ? 's' : '').' : '.$this->ariary($report['amount']).' à verser.'
                : "{$report['done']} sur {$report['total']} paies marquées payées. {$failed} refusée".($failed > 1 ? 's' : '').' — détail ci-dessous.')
            ->with('status_type', match (true) {
                $report['done'] === 0 => 'danger',
                $failed > 0 => 'warning',
                default => 'success',
            })
            ->with('bulk_report', $report);
    }

    public function cancel(Request $request, SalaryPayment $payment, PaySalaryAction $action): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif'])['reason'];
        $action->cancel($payment, $reason, $request->user());

        return back()->with('status', "Paie de {$payment->employee_name} annulée : ses avantages repassent en attente.");
    }

    /** Les bulletins du mois : tous, ou les salariés cochés (`uuids[]`). */
    public function payslips(Request $request, PayrollBoard $board): Response
    {
        $data = $request->validate([
            'mois' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'uuids' => ['nullable', 'array', 'max:'.self::BULK_LIMIT],
            'uuids.*' => ['uuid'],
        ]);
        $month = $this->month($data['mois'] ?? null) ?? now()->startOfMonth();
        $rows = collect($board->month($month, $data['uuids'] ?? null)['rows'])
            ->map(fn (array $row) => [...$row, 'seniority' => $row['hire_date'] ? Seniority::of(Carbon::parse($row['hire_date']), $month->copy()->endOfMonth())['label'] : null])
            ->values();

        return Inertia::render('Administration/Payroll/Payslips', [
            'month' => $month->format('Y-m'),
            'rows' => $rows->all(),
        ]);
    }

    /** Journal de paie (`type=journal`) ou liste de virement (`type=virements`) du mois, en Excel. */
    public function export(Request $request, PayrollBoard $board, ExcelWorkbook $excel, Auditor $auditor): StreamedResponse
    {
        $data = $request->validate([
            'mois' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'type' => ['nullable', 'in:journal,virements'],
            'uuids' => ['nullable', 'array', 'max:'.self::BULK_LIMIT],
            'uuids.*' => ['uuid'],
        ]);
        $month = $this->month($data['mois'] ?? null) ?? now()->startOfMonth();
        $type = $data['type'] ?? 'journal';
        $result = $board->month($month, $data['uuids'] ?? null);
        $rows = collect($result['rows'])->filter(fn ($row) => $row['payable'] || $row['payment'] !== null)->values();
        $amount = fn ($value) => round((float) $value, 2);
        $period = $month->format('Y-m');

        $auditor->record('payroll.export', null, ['period' => $period, 'type' => $type, 'rows' => $rows->count()], module: 'payroll');

        if ($type === 'virements') {
            $rows = $rows->sortBy(fn ($row) => [$row['payment_mode']['label'], $row['payment_mode']['details']['bank'] ?? '', $row['name']])->values();

            return $excel->download("virements-paie-{$period}", 'Virements', [
                'Mode de paiement', 'Banque', 'Code banque', 'N° de compte / numéro', 'Titulaire', 'Matricule', 'Nom', 'Net à verser (Ar)', 'Statut',
            ], $rows->map(fn ($row) => [
                $row['payment_mode']['label'],
                $row['payment_mode']['details']['bank_name'] ?? $row['payment_mode']['details']['bank'] ?? '',
                $row['payment_mode']['details']['bank_code'] ?? '',
                $row['payment_mode']['mode'] === 'MOBILE_MONEY' ? $row['payment_mode']['summary'] : ($row['payment_mode']['details']['account_number'] ?? ''),
                $row['payment_mode']['details']['account_holder'] ?? collect($row['payment_mode']['details']['accounts'] ?? [])->pluck('holder')->filter()->implode(', '),
                $row['employee_number'],
                $row['name'],
                $amount($row['total']),
                $row['payment'] ? 'Payée' : 'À payer',
            ]));
        }

        return $excel->download("journal-paie-{$period}", 'Journal de paie', [
            'Matricule', 'Nom', 'Fonction', 'Service', 'Salaire de base (Ar)', 'Avantages (Ar)', 'Brut (Ar)',
            'CNAPS (Ar)', $result['settings']['health_label'].' (Ar)', 'IRSA (Ar)', 'Retenues de dettes (Ar)', 'Net à verser (Ar)',
            'Charges patronales (Ar)', 'Coût employeur (Ar)', 'Mode de paiement', 'Statut',
        ], $rows->map(function ($row) use ($amount) {
            $kind = fn (string $kind) => -array_sum(array_map(fn ($line) => $line['kind'] === $kind ? (float) $line['amount'] : 0, $row['lines']));

            return [
                $row['employee_number'], $row['name'], $row['job_title'] ?? '', $row['department'] ?? '',
                $amount($row['base_amount']), $amount($row['advantages_amount']), $amount($row['gross']),
                $amount($kind('CNAPS')), $amount($kind('HEALTH')), $amount($kind('IRSA')), $amount($row['debts_amount']), $amount($row['total']),
                $amount($row['employer_amount']), $amount($row['cost']),
                $row['payment_mode']['label'], $row['payment'] ? 'Payée' : 'À payer',
            ];
        }));
    }

    private function month(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', "{$value}-01")->startOfDay();
    }

    private function ariary(string|float $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' Ar';
    }
}
