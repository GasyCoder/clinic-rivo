<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\DispenseMedicinesAction;
use App\Actions\Pharmacy\PrepareDispenseInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\DispenseMedicinesRequest;
use App\Models\PharmacyDispense;
use App\Services\Pharmacy\PharmacyWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DispenseController extends Controller
{
    public function index(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/Dispenses/Index', $workspace->dispenses($request->user()));
    }

    public function prepareInvoice(
        Request $request,
        PharmacyDispense $dispense,
        PrepareDispenseInvoiceAction $action,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('prepareInvoice', $dispense);
        $dispense = $action->execute($dispense, $request->user());

        return back()->with('status', "Ticket {$dispense->invoice->invoice_number} transmis à la Caisse.");
    }

    public function ticket(Request $request, PharmacyDispense $dispense): Response
    {
        Gate::forUser($request->user())->authorize('printTicket', $dispense);
        $directPrint = $request->boolean('direct') && $request->boolean('print');
        $embeddedPrint = $request->boolean('embedded') && $request->boolean('print');
        $ticketOnly = $directPrint || $embeddedPrint;

        $invoice = $dispense->invoice()->with([
            'patient:id,uuid,patient_number,first_name,last_name',
            'episode:id,uuid,episode_number',
            'lines.billableItem:id,source_module',
            'creator:id,name',
            'validator:id,name',
        ])->firstOrFail();

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
            'returnToCash' => false,
            'returnToPharmacy' => ! $ticketOnly,
            'ticketOnly' => $ticketOnly,
            'autoPrint' => $request->boolean('print'),
            'closeAfterPrint' => $directPrint && ! $embeddedPrint,
            'externalPrescriber' => $dispense->external_prescriber,
        ]);
    }

    public function deliver(
        DispenseMedicinesRequest $request,
        PharmacyDispense $dispense,
        DispenseMedicinesAction $action,
    ): RedirectResponse {
        $event = $action->execute($dispense, $request->validated(), $request->user());

        return back()->with('status', "Bon de sortie {$event->delivery_number} enregistré. Le stock a été mis à jour.");
    }
}
