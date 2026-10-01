<?php

namespace App\Http\Controllers;

use App\Actions\StaffDebts\CollectStaffDebtRepaymentAction;
use App\Enums\StaffDebtRepaymentSource;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Services\StaffDebts\StaffDebtDirectory;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-228 — la Caisse encaisse le remboursement en espèces d'une dette du personnel
 * (seule la Caisse encaisse, ADR-012), dans la session de celui qui encaisse, remet un
 * reçu, et annule un encaissement fait par erreur tant que sa caisse est ouverte.
 */
class CashStaffDebtController extends Controller
{
    public function collect(Request $request, StaffDebt $staffDebt, CollectStaffDebtRepaymentAction $action): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'cash_register_uuid' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => 'montant', 'note' => 'note']);

        $repayment = $action->execute($staffDebt, [...$data, 'amount' => (string) $data['amount']], $request->user());

        return back()->with('status', 'Remboursement '.$repayment->receipt_number.' encaissé : '.number_format((float) $repayment->amount, 0, ',', ' ').' Ar. Son reçu est dans « Encaissés dans votre caisse ».');
    }

    public function reverse(Request $request, StaffDebtRepayment $staffDebtRepayment, CollectStaffDebtRepaymentAction $action): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'motif'])['reason'];
        $repayment = $action->reverse($staffDebtRepayment, $reason, $request->user());

        return back()->with('status', "Encaissement {$repayment->receipt_number} annulé.");
    }

    public function receipt(StaffDebtRepayment $staffDebtRepayment, StaffDebtDirectory $directory): Response
    {
        abort_unless($staffDebtRepayment->source === StaffDebtRepaymentSource::Cash, 404);
        $staffDebtRepayment->loadMissing(['debt', 'recorder:id,name', 'reverser:id,name', 'cashSession.register:id,name']);
        $debt = $staffDebtRepayment->debt;

        return Inertia::render('Cash/StaffDebtReceipt', [
            'receipt' => [
                ...$directory->repayment($staffDebtRepayment),
                'debt_number' => $debt->number,
                'employee_name' => $debt->employee_name,
                'employee_number' => $debt->employee_number,
                'register_name' => $staffDebtRepayment->cashSession?->register?->name,
                // Le reste dû aujourd'hui, pas celui du jour de l'encaissement : un reçu réimprimé dit la vérité du moment.
                'balance' => Money::fromMinor($debt->balanceMinor()),
                'debt_amount' => (string) $debt->amount,
            ],
        ]);
    }
}
