<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveHrDocumentAction;
use App\Actions\Administration\RestoreHrDocumentAction;
use App\Actions\Administration\StoreHrDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveHrDocumentRequest;
use App\Http\Requests\Administration\StoreHrDocumentRequest;
use App\Models\HrDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HrDocumentController extends Controller
{
    public function store(StoreHrDocumentRequest $request, StoreHrDocumentAction $action): RedirectResponse
    {
        $document = $action->execute($request->validated(), $request->user());

        return to_route('administration.employees.show', $document->employee)
            ->with('status', 'Document RH ajouté au dossier privé.');
    }

    public function show(Request $request, HrDocument $document): BinaryFileResponse
    {
        Gate::forUser($request->user())->authorize('view', $document);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return response()->file(Storage::disk('local')->path($document->path), [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, HrDocument $document): BinaryFileResponse
    {
        Gate::forUser($request->user())->authorize('view', $document);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return response()->download(
            Storage::disk('local')->path($document->path),
            $document->original_name,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function destroy(ArchiveHrDocumentRequest $request, HrDocument $document, ArchiveHrDocumentAction $action): RedirectResponse
    {
        $employee = $document->employee;
        $action->execute($document, $request->validated('reason'), $request->user());

        return to_route('administration.employees.show', $employee)
            ->with('status', 'Document RH archivé.');
    }

    public function restore(Request $request, HrDocument $document, RestoreHrDocumentAction $action): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('restore', $document);
        $document = $action->execute($document, $request->user());

        return to_route('administration.employees.show', $document->employee)
            ->with('status', 'Document RH restauré.');
    }
}
