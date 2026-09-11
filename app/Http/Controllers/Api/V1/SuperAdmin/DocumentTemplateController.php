<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Administration\ArchiveDocumentTemplateAction;
use App\Actions\Administration\DuplicateDocumentTemplateAction;
use App\Actions\Administration\RestoreDocumentTemplateAction;
use App\Actions\Administration\RevertDocumentTemplateVersionAction;
use App\Actions\Administration\SaveDocumentTemplateAction;
use App\Actions\Administration\ToggleDocumentTemplateActiveAction;
use App\Enums\DocumentDataContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CatalogReasonRequest;
use App\Http\Requests\Api\V1\SuperAdmin\DocumentTemplateDataRequest;
use App\Models\DocumentTemplate;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'document_templates.view');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
            'document_type' => ['nullable', 'string', 'max:80'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $status = $validated['status'] ?? 'ALL';

        $query = DocumentTemplate::query()
            ->withCount('generatedDocuments')
            ->when($status === 'ARCHIVED', fn ($query) => $query->onlyTrashed())
            ->when($status === 'ALL', fn ($query) => $query->withTrashed())
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('document_type', 'like', "%{$search}%");
            }))
            ->when(filled($validated['document_type'] ?? null), fn ($query) => $query->where('document_type', mb_strtoupper($validated['document_type'])))
            ->orderBy('name');

        return response()->json([
            'data' => [
                'templates' => $query->get()->map(fn (DocumentTemplate $template) => $this->serialize($template))->values(),
                'summary' => [
                    'active' => DocumentTemplate::query()->count(),
                    'archived' => DocumentTemplate::onlyTrashed()->count(),
                ],
                'document_types' => DocumentTemplate::query()->withTrashed()
                    ->distinct()->orderBy('document_type')->pluck('document_type')->values(),
                'data_contexts' => collect(DocumentDataContext::cases())->map(fn ($context) => [
                    'value' => $context->value, 'label' => $context->label(),
                ])->values(),
            ],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function show(Request $request, string $documentTemplateUuid): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'document_templates.view');
        $template = DocumentTemplate::withTrashed()->where('uuid', $documentTemplateUuid)->firstOrFail();

        return response()->json(['data' => $this->serialize($this->loadTemplate($template))]);
    }

    public function store(DocumentTemplateDataRequest $request, SaveDocumentTemplateAction $action): JsonResponse
    {
        $template = $action->execute($request->validated(), CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "Canevas « {$template->name} » créé sur le site.",
            'data' => $this->serialize($this->loadTemplate($template)),
        ], 201);
    }

    public function update(
        DocumentTemplateDataRequest $request,
        string $documentTemplateUuid,
        SaveDocumentTemplateAction $action,
    ): JsonResponse {
        $template = DocumentTemplate::withTrashed()->where('uuid', $documentTemplateUuid)->firstOrFail();
        abort_if($template->trashed(), 422, 'Un canevas archivé doit être restauré avant modification.');
        $template = $action->execute($request->validated(), CatalogActor::fromRemoteRequest($request), $template);

        return response()->json([
            'message' => "Canevas « {$template->name} » mis à jour.",
            'data' => $this->serialize($this->loadTemplate($template)),
        ]);
    }

    public function destroy(
        CatalogReasonRequest $request,
        string $documentTemplateUuid,
        ArchiveDocumentTemplateAction $action,
    ): JsonResponse {
        $template = DocumentTemplate::query()->where('uuid', $documentTemplateUuid)->firstOrFail();
        $action->execute($template, $request->validated('reason'), CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Canevas « {$template->name} » archivé."]);
    }

    public function restore(
        Request $request,
        string $documentTemplateUuid,
        RestoreDocumentTemplateAction $action,
    ): JsonResponse {
        $actor = CatalogActor::fromRemoteRequest($request);
        $template = DocumentTemplate::onlyTrashed()->where('uuid', $documentTemplateUuid)->firstOrFail();
        $template = $action->execute($template, $actor);

        return response()->json([
            'message' => "Canevas « {$template->name} » restauré.",
            'data' => $this->serialize($this->loadTemplate($template)),
        ]);
    }

    public function duplicate(
        Request $request,
        string $documentTemplateUuid,
        DuplicateDocumentTemplateAction $action,
    ): JsonResponse {
        $template = DocumentTemplate::withTrashed()->where('uuid', $documentTemplateUuid)->firstOrFail();
        $copy = $action->execute($template, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "Canevas dupliqué en « {$copy->name} », inactif.",
            'data' => $this->serialize($this->loadTemplate($copy)),
        ], 201);
    }

    public function history(Request $request, string $documentTemplateUuid): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'document_templates.view');
        $template = DocumentTemplate::withTrashed()->where('uuid', $documentTemplateUuid)->firstOrFail();

        return response()->json([
            'data' => $template->versions()->get()->map(fn (DocumentTemplate $version) => [
                'uuid' => $version->uuid,
                'name' => $version->name,
                'archived' => $version->trashed(),
                'archived_at' => $version->deleted_at?->toIso8601String(),
                'archive_reason' => $version->delete_reason,
                'generated_documents_count' => $version->generatedDocuments()->count(),
                'creator' => $version->creator?->name ?? $version->external_created_by_name,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function revert(
        CatalogReasonRequest $request,
        string $documentTemplateUuid,
        RevertDocumentTemplateVersionAction $action,
    ): JsonResponse {
        // withTrashed(), not onlyTrashed(): reverting to the currently
        // active version is a real (if pointless) request, not a 404 — let
        // the action itself reject it with a clear message.
        $historicalVersion = DocumentTemplate::withTrashed()->where('uuid', $documentTemplateUuid)->firstOrFail();
        $replacement = $action->execute($historicalVersion, $request->validated('reason'), CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "Retour à la version du {$historicalVersion->created_at->translatedFormat('d/m/Y H:i')}.",
            'data' => $this->serialize($this->loadTemplate($replacement)),
        ]);
    }

    public function activate(Request $request, string $documentTemplateUuid, ToggleDocumentTemplateActiveAction $action): JsonResponse
    {
        return $this->toggle($request, $documentTemplateUuid, true, $action);
    }

    public function deactivate(Request $request, string $documentTemplateUuid, ToggleDocumentTemplateActiveAction $action): JsonResponse
    {
        return $this->toggle($request, $documentTemplateUuid, false, $action);
    }

    private function toggle(Request $request, string $documentTemplateUuid, bool $active, ToggleDocumentTemplateActiveAction $action): JsonResponse
    {
        $template = DocumentTemplate::query()->where('uuid', $documentTemplateUuid)->firstOrFail();
        $template = $action->execute($template, $active, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => $active ? "Canevas « {$template->name} » activé." : "Canevas « {$template->name} » désactivé.",
            'data' => $this->serialize($this->loadTemplate($template)),
        ]);
    }

    private function authorizeActor(CatalogActor $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }

    private function loadTemplate(DocumentTemplate $template): DocumentTemplate
    {
        return $template->fresh()->loadCount('generatedDocuments');
    }

    /** @return array<string, mixed> */
    private function serialize(DocumentTemplate $template): array
    {
        return [
            'uuid' => $template->uuid,
            'document_type' => $template->document_type,
            'data_context' => $template->data_context->value,
            'data_context_label' => $template->data_context->label(),
            'name' => $template->name,
            'description' => $template->description,
            'content' => $template->content,
            'content_html' => $template->content_html,
            'variables_used' => $template->variables_used ?? [],
            'active' => $template->active,
            'archived' => $template->trashed(),
            'archived_at' => $template->deleted_at?->toIso8601String(),
            'archive_reason' => $template->delete_reason,
            'generated_documents_count' => $template->generated_documents_count ?? 0,
            'creator' => $template->creator?->name ?? $template->external_created_by_name,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
