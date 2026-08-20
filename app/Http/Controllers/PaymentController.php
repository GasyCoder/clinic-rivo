<?php

namespace App\Http\Controllers;

use App\Actions\Payment\CancelPaymentAction;
use App\Actions\Payment\RecordPaymentAction;
use App\Http\Requests\CancelPaymentRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    public function store(
        RecordPaymentRequest $request,
        Patient $patient,
        RecordPaymentAction $action,
    ): RedirectResponse {
        $payment = $action->execute($patient, $request->validated(), $request->user());

        return back()->with(
            'status',
            "Paiement {$payment->payment_number} enregistré. Reçu {$payment->receipt->receipt_number} disponible.",
        );
    }

    public function cancel(
        CancelPaymentRequest $request,
        Payment $payment,
        CancelPaymentAction $action,
    ): RedirectResponse {
        $action->execute($payment, $request->validated('reason'), $request->user());

        return back()->with('status', "Paiement {$payment->payment_number} annulé. L’historique et le reçu sont conservés.");
    }
}
