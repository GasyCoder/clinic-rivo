<?php

namespace App\Http\Controllers;

use App\Actions\Patient\StorePatientMutualCoverageAttachmentsAction;
use App\Http\Requests\StorePatientMutualCoverageAttachmentsRequest;
use App\Models\Patient;
use App\Models\PatientMutualCoverage;
use App\Models\PatientMutualCoverageAttachment;
use App\Services\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientMutualCoverageAttachmentController extends Controller
{
    /** @var array<int, string> */
    private const INLINE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __invoke(
        Request $request,
        PatientMutualCoverage $coverage,
        PatientMutualCoverageAttachment $attachment,
        Auditor $auditor,
    ): StreamedResponse {
        // Viewing the patient file and its private coverage documents are
        // separate capabilities. Both remain mandatory even when this URL
        // is opened directly instead of from the patient screen.
        abort_unless($request->user()?->can('patients.view'), 403);
        abort_unless($request->user()?->can('patient_coverage_documents.view'), 403);
        abort_unless($attachment->patient_mutual_coverage_id === $coverage->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        abort_unless(in_array($attachment->mime_type, self::INLINE_MIME_TYPES, true), 415);

        $auditor->record(
            'patient_coverage_document.view',
            entity: $attachment,
            module: 'reception',
            actor: $request->user(),
        );

        return Storage::disk('local')->response(
            $attachment->path,
            $this->safeInlineName($attachment->original_name),
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Referrer-Policy' => 'no-referrer',
            ],
            'inline',
        );
    }

    public function store(
        StorePatientMutualCoverageAttachmentsRequest $request,
        Patient $patient,
        PatientMutualCoverage $mutualCoverage,
        StorePatientMutualCoverageAttachmentsAction $action,
    ): RedirectResponse {
        // Scoped route binding and the FormRequest both enforce ownership.
        // Keep this explicit guard as defence in depth if route scoping is
        // changed later.
        abort_unless($mutualCoverage->patient_id === $patient->id, 404);

        $attachments = $action->execute(
            $mutualCoverage,
            $request->file('files', []),
            $request->user(),
        );

        return back()->with(
            'status',
            $attachments->count() === 1
                ? 'Justificatif de mutuelle ajouté.'
                : "{$attachments->count()} justificatifs de mutuelle ajoutés.",
        );
    }

    private function safeInlineName(string $originalName): string
    {
        $name = basename(str_replace('\\', '/', $originalName));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $name) ?: 'document';

        return Str::limit($name, 255, '');
    }
}
