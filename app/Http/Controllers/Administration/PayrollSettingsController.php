<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Payroll\UpdatePayrollSettingsAction;
use App\Enums\EmployeeRemunerationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollSettingsRequest;
use App\Models\PayrollSetting;
use App\Services\Payroll\PayrollCalculator;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-233 — les paramètres de paie du site (cotisations, barème IRSA), une page de la Paie
 * servie aussi au portail (ADR-187). La simulation calcule un bulletin sur des paramètres
 * pas encore enregistrés, sans rien écrire.
 */
class PayrollSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $settings = PayrollSetting::current();
        $settings->loadMissing('updater:id,name');

        return Inertia::render('Administration/Payroll/Settings', [
            'settings' => $settings->snapshot(),
            'configured' => $settings->exists,
            'updatedAt' => $settings->updated_at?->toIso8601String(),
            'updatedBy' => $settings->exists ? RemoteActorAttribution::name($settings->updater?->name, $settings->external_updated_by_name) : null,
            'proposal' => (new PayrollSetting(PayrollSetting::PROPOSAL))->snapshot(),
            'canUpdate' => $request->user()->can('salary_settings.update'),
        ]);
    }

    public function update(PayrollSettingsRequest $request, UpdatePayrollSettingsAction $action): RedirectResponse
    {
        $settings = $action->execute($request->settings(), $request->user());

        return back()->with('status', $settings->legal_deductions_enabled
            ? 'Paramètres de paie enregistrés : les retenues légales s’appliquent aux paies non encore payées.'
            : 'Paramètres de paie enregistrés. Les retenues légales restent désactivées.');
    }

    public function simulate(PayrollSettingsRequest $request, PayrollCalculator $calculator): JsonResponse
    {
        $input = $request->validate([
            'gross' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'remuneration_type' => ['nullable', new Enum(EmployeeRemunerationType::class)],
        ]);
        $rules = $request->settings();
        // La simulation montre toujours le calcul, même avant l'activation.
        $rules['legal_deductions_enabled'] = true;
        $type = EmployeeRemunerationType::tryFrom((string) ($input['remuneration_type'] ?? 'SALARY')) ?? EmployeeRemunerationType::Salary;
        $gross = Money::toMinor(number_format((float) $input['gross'], 2, '.', ''));
        $result = $calculator->compute($gross, $type, (int) ($input['children'] ?? 0), $rules);
        $deductions = $result['cnaps'] + $result['health'] + $result['irsa'];

        return response()->json([
            'applies' => $result['applies'],
            'reason' => $result['reason'],
            'gross' => Money::fromMinor($gross),
            'cnaps' => Money::fromMinor($result['cnaps']),
            'health' => Money::fromMinor($result['health']),
            'taxable' => Money::fromMinor($result['taxable']),
            'irsa_before_reduction' => Money::fromMinor($result['irsa_before_reduction']),
            'child_reduction' => Money::fromMinor($result['child_reduction']),
            'irsa' => Money::fromMinor($result['irsa']),
            'net' => Money::fromMinor($gross - $deductions),
            'employer' => Money::fromMinor($result['employer_cnaps'] + $result['employer_health']),
            'employer_cnaps' => Money::fromMinor($result['employer_cnaps']),
            'employer_health' => Money::fromMinor($result['employer_health']),
            'cost' => Money::fromMinor($gross + $result['employer_cnaps'] + $result['employer_health']),
        ]);
    }
}
