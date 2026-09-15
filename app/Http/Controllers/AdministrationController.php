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
            'siteName' => config('rivo.site.name'),
        ]);
    }
}
