<?php

namespace App\Http\Controllers;

use App\Services\Administration\HrOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdministrationController extends Controller
{
    public function __invoke(Request $request, HrOverviewService $overview): Response
    {
        return Inertia::render('Administration/Index', [
            'summary' => $overview->summary(),
            // L'effectif par département : la même lecture que celle servie au portail.
            'departments' => $overview->departments(),
            'siteName' => config('rivo.site.name'),
        ]);
    }
}
