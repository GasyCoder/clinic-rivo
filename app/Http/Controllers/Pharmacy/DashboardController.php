<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * ADR-098 — the Pharmacy home now lives on the account's overview page.
 * The address stays valid so bookmarks and older links still land there.
 */
class DashboardController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return to_route('dashboard');
    }
}
