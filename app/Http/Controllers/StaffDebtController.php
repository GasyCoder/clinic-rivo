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
 * siennes : son compte est relié à sa fiche (ADR-188). ADR-234 — il ne demande que le
 * montant et accepte les règles du site ; le DG fixe le remboursement.
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
        // ADR-234 — le montant seulement : le remboursement par mois et le premier mois sont fixés par le DG.
        $fixedByDg = 'Le remboursement par mois et le premier mois sont fixés par le DG à sa décision.';
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'accept_terms' => ['accepted'],
            'terms_version' => ['required', 'string', 'max:64'],
            'installment_amount' => ['prohibited'],
            'first_period' => ['prohibited'],
        ], [
            'accept_terms.accepted' => 'Acceptez les règles et les conditions pour envoyer la demande.',
            'terms_version.required' => 'Les règles du site n’ont pas été lues : rechargez la page.',
            'installment_amount.prohibited' => $fixedByDg,
            'first_period.prohibited' => $fixedByDg,
        ], [
            'amount' => 'montant', 'reason' => 'motif',
        ]);

        $debt = $action->execute($request->user(), [
            'amount' => (string) $data['amount'],
            'reason' => $data['reason'] ?? null,
            'accept_terms' => true,
            'terms_version' => $data['terms_version'],
        ]);

        return redirect()->route('my-debts.index', ['dette' => $debt->uuid])
            ->with('status', "Demande {$debt->number} envoyée au DG. Il fixera le remboursement ; vous serez prévenu de sa décision.");
    }

    public function withdraw(Request $request, StaffDebt $staffDebt, RequestStaffDebtAction $action): RedirectResponse
    {
        $debt = $action->withdraw($staffDebt, $request->user());

        return back()->with('status', "Demande {$debt->number} retirée.");
    }
}
