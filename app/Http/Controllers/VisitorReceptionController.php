<?php

namespace App\Http\Controllers;

use App\Actions\Reception\CloseVisitorVisitAction;
use App\Actions\Reception\RegisterVisitorVisitAction;
use App\Http\Requests\StoreVisitorVisitRequest;
use App\Models\Patient;
use App\Models\VisitorVisit;
use App\Models\VisitorVisitAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisitorReceptionController extends Controller
{
    public function index(Request $request): Response
    {
        $patientSearch = trim((string) $request->query('patient_q', ''));

        $patientMatches = $patientSearch !== ''
            ? Patient::query()
                ->where(function ($query) use ($patientSearch) {
                    $query->where('patient_number', 'like', "%{$patientSearch}%")
                        ->orWhere('first_name', 'like', "%{$patientSearch}%")
                        ->orWhere('last_name', 'like', "%{$patientSearch}%")
                        ->orWhere('phone', 'like', "%{$patientSearch}%");
                })
                ->limit(8)
                ->get(['uuid', 'patient_number', 'first_name', 'last_name', 'birth_date', 'phone'])
            : collect();

        $relations = [
            'patient:id,uuid,patient_number,first_name,last_name',
            'enteredBy:id,name',
            'closedBy:id,name',
            'attachments',
        ];

        $presentVisitors = VisitorVisit::query()
            ->with($relations)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->limit(30)
            ->get();

        $recentDepartures = VisitorVisit::query()
            ->with($relations)
            ->whereNotNull('checked_out_at')
            ->latest('checked_out_at')
            ->limit(20)
            ->get();

        return Inertia::render('Reception/Visitors/Index', [
            'patientSearch' => $patientSearch,
            'patientMatches' => $patientMatches,
            'presentVisitors' => $presentVisitors,
            'recentDepartures' => $recentDepartures,
        ]);
    }

    public function store(StoreVisitorVisitRequest $request, RegisterVisitorVisitAction $action): RedirectResponse
    {
        $visitor = $action->execute($request->validated());

        return redirect()->route('reception.visitors.index')
            ->with('status', "Entrée de {$visitor->full_name} enregistrée.");
    }

    public function close(VisitorVisit $visitorVisit, CloseVisitorVisitAction $action): RedirectResponse
    {
        $visitor = $action->execute($visitorVisit);

        return redirect()->route('reception.visitors.index')
            ->with('status', "Sortie de {$visitor->full_name} enregistrée.");
    }

    public function professionalAttachment(VisitorVisit $visitorVisit, VisitorVisitAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->visitor_visit_id === $visitorVisit->id, 404);

        $path = $attachment->path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
