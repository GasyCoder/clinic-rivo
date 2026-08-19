<?php

namespace App\Http\Controllers;

use App\Models\SurgicalRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only entry point for the Chirurgie module (CDC GitHub §15/16).
 * Scheduling, preoperative validation, intervention, anesthesia, report and
 * discharge each need their own dedicated screens — deliberately not built
 * here, this is only the list the sidebar link lands on.
 */
class SurgeryController extends Controller
{
    public function index(): Response
    {
        $surgicalRequests = SurgicalRequest::query()
            ->with(['episode.patient', 'surgeon'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Surgery/Index', [
            'surgicalRequests' => $surgicalRequests,
        ]);
    }
}
