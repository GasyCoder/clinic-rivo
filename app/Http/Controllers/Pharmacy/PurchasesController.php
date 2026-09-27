<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Services\Pharmacy\PurchasesOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** ADR-098 — « Achats » opens on the first tab this account may see. */
class PurchasesController extends Controller
{
    public function __invoke(Request $request, PurchasesOverview $purchases): RedirectResponse
    {
        return redirect($purchases->firstTab($request->user()));
    }
}
