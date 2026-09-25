<?php

namespace App\Http\Controllers;

use App\Actions\Discounts\CancelPatientDiscountAction;
use App\Actions\Discounts\GrantPatientDiscountAction;
use App\Models\Patient;
use App\Models\PatientDiscount;
use App\Support\Billing\DiscountRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-192 — la remise propre à un patient précis, accordée ou annulée depuis son
 * dossier par une personne habilitée (`discounts.approve`).
 */
class PatientDiscountController extends Controller
{
    public function store(Request $request, Patient $patient, GrantPatientDiscountAction $action): RedirectResponse
    {
        $validated = $request->validate([
            ...DiscountRules::pair('discount_type', 'discount_value'),
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from', 'after_or_equal:today'],
        ], [
            ...DiscountRules::messages('discount_type', 'discount_value'),
            'reason.required' => 'Indiquez le motif de la remise.',
            'reason.min' => 'Le motif tient en 3 caractères au moins.',
            'valid_until.after_or_equal' => 'La fin de validité ne peut pas être passée ni précéder le début.',
        ]);

        $discount = $action->execute($patient, $validated, $request->user());

        return back()->with('status', 'Remise de '.$discount->discount_type->describe((string) $discount->discount_value).' accordée à ce patient.');
    }

    public function cancel(Request $request, PatientDiscount $patientDiscount, CancelPatientDiscountAction $action): RedirectResponse
    {
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Indiquez pourquoi cette remise est annulée.', 'reason.min' => 'Le motif tient en 3 caractères au moins.'],
        );

        $action->execute($patientDiscount, $validated['reason'], $request->user());

        return back()->with('status', 'Remise du patient annulée.');
    }
}
