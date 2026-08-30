<?php

namespace App\Http\Controllers;

use App\Models\SurgicalRequest;
use App\Services\Care\CareRecordReadModel;
use App\Services\Surgery\SurgicalCaseWorkspace;
use App\Support\SurgeryReferenceData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnesthesiaWorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $surgicalRequests = SurgicalRequest::query()
            ->with(['episode.patient', 'surgeon', 'anesthesiaRecord.anesthetist'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($case) use ($search): void {
                    $case->where('procedure_name', 'like', "%{$search}%")
                        ->orWhereHas('episode', function ($episode) use ($search): void {
                            $episode->where('episode_number', 'like', "%{$search}%")
                                ->orWhereHas('patient', fn ($patient) => $patient
                                    ->where('patient_number', 'like', "%{$search}%")
                                    ->orWhere('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%"));
                        });
                });
            })
            ->orderByRaw("status in ('PENDING', 'SCHEDULED', 'PREOPERATIVE_VALIDATED', 'IN_PROGRESS') desc")
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Anesthesia/Index', [
            'surgicalRequests' => $surgicalRequests,
            'search' => $search,
        ]);
    }

    public function show(
        Request $request,
        SurgicalRequest $surgicalRequest,
        SurgicalCaseWorkspace $workspace,
        CareRecordReadModel $careRecordReadModel,
    ): Response {
        $careRecord = $surgicalRequest->episode()->with('careRecord')->first()?->careRecord;

        return Inertia::render('Surgery/Show', [
            'workspace' => 'anesthesia',
            'surgicalRequest' => $workspace->loadForAnesthesia($surgicalRequest),
            'careSummary' => $careRecordReadModel->present($careRecord, $request->user()),
            'users' => [],
            'teamFunctions' => [],
            'procedures' => [],
            'anesthesiaItems' => SurgeryReferenceData::anesthesiaItems(),
        ]);
    }
}
