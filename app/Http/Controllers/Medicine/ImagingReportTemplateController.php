<?php

namespace App\Http\Controllers\Medicine;

use App\Actions\Medicine\ArchiveImagingReportTemplateAction;
use App\Actions\Medicine\CreateImagingReportTemplateAction;
use App\Actions\Medicine\SetImagingExamDefaultTemplateAction;
use App\Actions\Medicine\UpdateImagingReportTemplateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImagingReportTemplateRequest;
use App\Http\Requests\UpdateImagingReportTemplateRequest;
use App\Models\ImagingReportTemplate;
use App\Models\ImagingRequestItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** ADR-108 — les feuilles de compte rendu ajoutées par les médecins du site. */
class ImagingReportTemplateController extends Controller
{
    public function store(StoreImagingReportTemplateRequest $request, CreateImagingReportTemplateAction $action): RedirectResponse
    {
        $template = $action->execute(
            $request->validated('name'),
            $request->validated('description'),
            $request->validated('body_html'),
            $request->user(),
            filled($request->validated('default_for_item_uuid'))
                ? ImagingRequestItem::query()->where('uuid', $request->validated('default_for_item_uuid'))->first()
                : null,
        );

        return back()->with('status', "La feuille « {$template->name} » est enregistrée.");
    }

    public function update(UpdateImagingReportTemplateRequest $request, ImagingReportTemplate $imagingReportTemplate, UpdateImagingReportTemplateAction $action): RedirectResponse
    {
        $template = $action->execute(
            $imagingReportTemplate,
            $request->validated('name'),
            $request->validated('description'),
            $request->validated('body_html'),
            $request->user(),
        );

        return back()->with('status', "La feuille « {$template->name} » est mise à jour.");
    }

    public function destroy(Request $request, ImagingReportTemplate $imagingReportTemplate, ArchiveImagingReportTemplateAction $action): RedirectResponse
    {
        $action->execute($imagingReportTemplate, $request->user());

        return back()->with('status', "La feuille « {$imagingReportTemplate->name} » est retirée.");
    }

    /** Règle (ou retire) la feuille proposée d'office pour l'examen de cette ligne. */
    public function setDefault(Request $request, ImagingRequestItem $imagingRequestItem, SetImagingExamDefaultTemplateAction $action): RedirectResponse
    {
        $validated = $request->validate(['template_key' => ['nullable', 'string', 'max:80']]);

        $action->execute($imagingRequestItem, $validated['template_key'] ?? null, $request->user());

        return back()->with('status', filled($validated['template_key'] ?? null)
            ? "Cette feuille est désormais proposée d’office pour « {$imagingRequestItem->catalog_item_name_snapshot} »."
            : "Plus aucune feuille n’est proposée d’office pour « {$imagingRequestItem->catalog_item_name_snapshot} ».");
    }
}
