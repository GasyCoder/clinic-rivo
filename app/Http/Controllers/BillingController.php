<?php

namespace App\Http\Controllers;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        $invoice->load([
            'patient:id,uuid,patient_number,first_name,last_name',
            'episode:id,uuid,episode_number',
            'lines.billableItem:id,source_module',
            'creator:id,name',
            'validator:id,name',
        ]);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    public function store(
        StoreInvoiceRequest $request,
        Patient $patient,
        CreateInvoiceAction $action,
    ): RedirectResponse {
        $invoice = $action->execute($patient, $request->validated(), $request->user());

        return back()->with('status', "Facture {$invoice->invoice_number} créée en brouillon.");
    }

    public function validateInvoice(
        Request $request,
        Invoice $invoice,
        ValidateInvoiceAction $action,
    ): RedirectResponse {
        $action->execute($invoice, $request->user());

        return back()->with('status', "Facture {$invoice->invoice_number} validée et prête à encaisser.");
    }
}
