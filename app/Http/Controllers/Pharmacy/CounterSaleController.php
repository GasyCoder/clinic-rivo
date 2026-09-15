<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\CreateExternalDispenseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StoreExternalDispenseRequest;
use App\Services\Pharmacy\PharmacyWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CounterSaleController extends Controller
{
    public function create(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/CounterSales/Create', $workspace->counterSale($request->user()));
    }

    public function store(StoreExternalDispenseRequest $request, CreateExternalDispenseAction $action): RedirectResponse
    {
        $dispense = $action->execute($request->validated(), $request->user());
        $response = to_route('pharmacy.counter-sales.create')
            ->with('status', "Vente comptoir préparée. Ticket {$dispense->invoice->invoice_number} transmis à la Caisse.");

        if ($request->boolean('print_after_create') && $request->user()->can('pharmacy.dispense.print')) {
            $response->with('print_ticket_url', route('pharmacy.dispenses.ticket.show', [
                'dispense' => $dispense,
                'print' => 1,
                'direct' => 1,
            ]));
        }

        return $response;
    }
}
