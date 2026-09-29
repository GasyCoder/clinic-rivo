<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveEmployeeBenefitAction;
use App\Actions\Administration\SaveEmployeeBenefitAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\EmployeeBenefitRequest;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-221 — les avantages et primes d'un employé, écrits depuis sa fiche.
 * Une correction enregistrée automatiquement revient sans message (`_autosave`).
 */
class EmployeeBenefitController extends Controller
{
    public function store(EmployeeBenefitRequest $request, Employee $employee, SaveEmployeeBenefitAction $action): RedirectResponse
    {
        $benefit = $action->execute($employee, $request->validated(), $request->user());

        return back()->with('status', "Avantage « {$benefit->benefitType?->label} » ajouté.");
    }

    public function update(EmployeeBenefitRequest $request, Employee $employee, EmployeeBenefit $benefit, SaveEmployeeBenefitAction $action): RedirectResponse
    {
        $action->execute($employee, $request->validated(), $request->user(), $benefit);

        return $request->boolean('_autosave') ? back() : back()->with('status', 'Avantage mis à jour.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeBenefit $benefit, ArchiveEmployeeBenefitAction $action): RedirectResponse
    {
        $reason = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Indiquez pourquoi cet avantage est retiré.'],
        )['reason'];
        $action->execute($employee, $benefit, $reason, $request->user());

        return back()->with('status', 'Avantage retiré. Il reste dans l’historique du dossier.');
    }
}
