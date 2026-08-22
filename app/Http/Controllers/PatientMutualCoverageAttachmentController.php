<?php

namespace App\Http\Controllers;

use App\Models\PatientMutualCoverage;
use App\Models\PatientMutualCoverageAttachment;
use App\Services\Audit\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientMutualCoverageAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        PatientMutualCoverage $coverage,
        PatientMutualCoverageAttachment $attachment,
        Auditor $auditor,
    ): StreamedResponse {
        abort_unless($request->user()?->can('patient_coverage_documents.view'), 403);
        abort_unless($attachment->patient_mutual_coverage_id === $coverage->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        $auditor->record(
            'patient_coverage_document.view',
            entity: $attachment,
            module: 'reception',
            actor: $request->user(),
        );

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
