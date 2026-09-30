<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Payroll\PaySalaryAction;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Services\Payroll\PayrollBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-227 — la paie du mois, une rubrique RH (servie aussi au portail, ADR-187) :
 * salaire de base déclaré + avantages du mois = montant à verser, brut. Marquer payé
 * fige la paie et les avantages qu'elle porte ; le virement se fait hors RIVO.
 */
class PayrollController extends Controller
{
    public function index(Request $request, PayrollBoard $board): Response
    {
        $month = $this->month($request->query('mois')) ?? now()->startOfMonth();

        return Inertia::render('Administration/Payroll/Index', [
            'month' => $month->format('Y-m'),
            'currentMonth' => now()->format('Y-m'),
            'board' => $board->month($month),
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

        return back()->with('status', "Paie de {$payment->employee_name} marquée payée : ".number_format((float) $payment->total_amount, 0, ',', ' ').' Ar.');
    }

    public function cancel(Request $request, SalaryPayment $payment, PaySalaryAction $action): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif'])['reason'];
        $action->cancel($payment, $reason, $request->user());

        return back()->with('status', "Paie de {$payment->employee_name} annulée : ses avantages repassent en attente.");
    }

    private function month(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', "{$value}-01")->startOfDay();
    }
}
