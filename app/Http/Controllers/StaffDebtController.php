<?php

namespace App\Http\Controllers;

use App\Actions\StaffDebts\RequestStaffDebtAction;
use App\Models\StaffDebt;
use App\Services\StaffDebts\StaffDebtDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-228 — « Mes dettes » : un membre du personnel demande une dette au DG depuis son
 * compte, suit la décision, le versement et ses remboursements. Il ne voit que les
 * siennes : son compte est relié à sa fiche (ADR-188).
 */
class StaffDebtController extends Controller
{
    public function index(Request $request, StaffDebtDirectory $directory): Response
    {
        return Inertia::render('StaffDebts/Mine', [
            'space' => $directory->mine($request->user()),
            'focus' => $request->string('dette')->toString() ?: null,
        ]);
    }

    public function store(Request $request, RequestStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'installment_amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'first_period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], [
            'amount' => 'montant', 'installment_amount' => 'mensualité', 'first_period' => 'premier mois', 'reason' => 'motif',
        ]);

        $debt = $action->execute($request->user(), [
            'amount' => (string) $data['amount'],
            'installment_amount' => (string) $data['installment_amount'],
            'first_period' => $data['first_period'],
            'reason' => $data['reason'],
        ]);

        return redirect()->route('my-debts.index', ['dette' => $debt->uuid])
            ->with('status', "Demande {$debt->number} envoyée au DG. Vous serez prévenu de sa décision.");
    }

    public function withdraw(Request $request, StaffDebt $staffDebt, RequestStaffDebtAction $action): RedirectResponse
    {
        $debt = $action->withdraw($staffDebt, $request->user());

        return back()->with('status', "Demande {$debt->number} retirée.");
    }
}
