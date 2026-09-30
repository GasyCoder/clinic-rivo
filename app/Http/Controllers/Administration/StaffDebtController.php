<?php

namespace App\Http\Controllers\Administration;

use App\Actions\StaffDebts\DecideStaffDebtAction;
use App\Actions\StaffDebts\DisburseStaffDebtAction;
use App\Enums\SalaryPaymentMode;
use App\Enums\StaffDebtRepaymentMode;
use App\Http\Controllers\Controller;
use App\Models\StaffDebt;
use App\Services\StaffDebts\StaffDebtDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-228 — les dettes du personnel, une rubrique RH servie aussi au portail (ADR-187) :
 * le RH les suit et marque versées celles que le DG a accordées ; le DG — le Super Admin
 * du portail, par le relais RH — les accorde, les ajuste, les refuse, annule un accord
 * pas encore versé ou remet un reste. Chaque geste garde son droit, revérifié par
 * l'action.
 */
class StaffDebtController extends Controller
{
    private const TERMS = [
        'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        'installment_amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        'first_period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
    ];

    private const NAMES = [
        'amount' => 'montant', 'installment_amount' => 'mensualité', 'first_period' => 'premier mois',
        'repayment_mode' => 'mode de remboursement', 'reason' => 'motif', 'note' => 'note',
    ];

    public function index(Request $request, StaffDebtDirectory $directory): Response
    {
        return Inertia::render('Administration/StaffDebts/Index', [
            'listing' => $directory->listing($request->query('vue'), $request->query('q')),
            'can' => [
                'decide' => $request->user()->can('staff_debts.decide'),
                'disburse' => $request->user()->can('staff_debts.disburse'),
            ],
        ]);
    }

    public function show(Request $request, StaffDebt $staffDebt, StaffDebtDirectory $directory): Response
    {
        return Inertia::render('Administration/StaffDebts/Show', [
            'debt' => $directory->detail($staffDebt, $request->user()),
            'repaymentModes' => collect(StaffDebtRepaymentMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all(),
            'disbursementModes' => collect(SalaryPaymentMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all(),
            'currentMonth' => now()->format('Y-m'),
            'today' => now()->toDateString(),
        ]);
    }

    public function approve(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate([
            ...self::TERMS,
            'amount' => ['required', ...array_slice(self::TERMS['amount'], 1)],
            'repayment_mode' => ['required', Rule::enum(StaffDebtRepaymentMode::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], self::NAMES);

        $debt = $action->approve($staffDebt, $this->strings($data), $request->user());

        return back()->with('status', "Dette {$debt->number} accordée à {$debt->employee_name}. Le RH la versera.");
    }

    public function refuse(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], self::NAMES);
        $debt = $action->refuse($staffDebt, $data['reason'], $request->user());

        return back()->with('status', "Demande {$debt->number} refusée.");
    }

    public function adjust(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate([
            ...self::TERMS,
            'repayment_mode' => ['required', Rule::enum(StaffDebtRepaymentMode::class)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [], self::NAMES);

        $debt = $action->adjust($staffDebt, $this->strings($data), $request->user());

        return back()->with('status', "Dette {$debt->number} ajustée.");
    }

    public function cancel(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], self::NAMES);
        $debt = $action->cancel($staffDebt, $data['reason'], $request->user());

        return back()->with('status', "Accord de la dette {$debt->number} annulé.");
    }

    public function writeOff(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], self::NAMES);
        $debt = $action->writeOff($staffDebt, $data['reason'], $request->user());

        return back()->with('status', "Reste de la dette {$debt->number} remis.");
    }

    public function disburse(Request $request, StaffDebt $staffDebt, DisburseStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate([
            'disbursed_on' => ['required', 'date_format:Y-m-d'],
            'disbursement_mode' => ['required', Rule::enum(SalaryPaymentMode::class)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['disbursed_on' => 'date du versement', 'disbursement_mode' => 'moyen', 'reference' => 'référence', 'note' => 'note']);

        $debt = $action->execute($staffDebt, $data, $request->user());

        return back()->with('status', "Dette {$debt->number} marquée versée : les remboursements commencent en ".$debt->first_period->translatedFormat('F Y').'.');
    }

    /** Les montants arrivent parfois en nombres (JSON) : l'argent se lit toujours en texte. @param array<string, mixed> $data */
    private function strings(array $data): array
    {
        foreach (['amount', 'installment_amount'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (string) $data[$key];
            }
        }

        return $data;
    }
}
