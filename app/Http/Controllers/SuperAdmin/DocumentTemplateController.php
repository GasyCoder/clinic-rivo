<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\DocumentDataContext;
use App\Http\Controllers\Controller;
use App\Services\Administration\DocumentFormFieldCatalog;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\Documents\DocumentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTemplateController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $selectedSite = mb_strtoupper(trim((string) $request->query('site', '')));

        if ($selectedSite !== '' && ! in_array($selectedSite, $this->siteCodes(), true)) {
            abort(404);
        }

        return Inertia::render('SuperAdmin/DocumentTemplates/Index', [
            'sites' => $client->documentTemplatesForAllSites($request->user(), ['status' => 'ALL']),
            'selectedSiteCode' => $selectedSite ?: null,
            'selectedFolder' => filled($request->query('dossier')) ? DocumentFamily::key((string) $request->query('dossier')) : null,
            'dataContexts' => $this->dataContextOptions(),
            'families' => $this->familyOptions(),
        ]);
    }

    public function create(Request $request, string $site, PortalSiteApiClient $client): Response
    {
        $this->assertRouteSite($site);
        // Unlike edit() (which already discovers this via the GET-detail
        // call), a brand-new canevas makes no API call up front — without
        // this check the Super Admin could compose a whole document only to
        // learn it can't be delivered when they finally click "Enregistrer".
        $this->assertSiteReachable($site);
        // ADR-208 — « Nouveau canevas » depuis un dossier arrive réglé sur son
        // type et sur le contexte que ce dossier attend.
        $folder = filled($request->query('type')) ? DocumentFamily::key((string) $request->query('type')) : null;

        return Inertia::render('SuperAdmin/DocumentTemplates/Editor', [
            'targetSite' => $this->siteMeta(mb_strtoupper($site)),
            'template' => null,
            'typeOptions' => $this->typeOptions($site, $request, $client),
            'dataContexts' => $this->dataContextOptions(),
            'families' => $this->familyOptions(),
            'preset' => $folder === null ? null : [
                'document_type' => $folder,
                'data_context' => DocumentFamily::context($folder)->value,
                'folder_label' => DocumentFamily::label($folder),
            ],
        ]);
    }

    public function edit(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): Response
    {
        $this->assertRouteSite($site);
        $detail = $client->documentTemplate($site, $documentTemplate, $request->user());
        abort_unless($detail['ok'], 503, $detail['message'] ?? 'Le site ne répond pas actuellement.');

        return Inertia::render('SuperAdmin/DocumentTemplates/Editor', [
            'targetSite' => $this->siteMeta(mb_strtoupper($site)),
            'template' => $detail['data'],
            'typeOptions' => $this->typeOptions($site, $request, $client),
            'dataContexts' => $this->dataContextOptions(),
            'families' => $this->familyOptions(),
            'preset' => null,
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $siteCode = $this->validatedSiteCode($request);
        $payload = $this->templatePayload($request);

        // ADR-208 — le canevas créé se retrouve dans son dossier.
        return $this->respond(
            $client->createDocumentTemplate($siteCode, $payload, $request->user()),
            'Modèle créé.',
            route('super-admin.document-templates.index', ['site' => $siteCode, 'dossier' => DocumentFamily::key($payload['document_type'])]),
        );
    }

    public function update(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertRouteSite($site);

        return $this->respond(
            $client->updateDocumentTemplate($site, $documentTemplate, $this->templatePayload($request), $request->user()),
            'Modèle mis à jour.',
        );
    }

    public function destroy(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertRouteSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->respond(
            $client->archiveDocumentTemplate($site, $documentTemplate, $validated['reason'], $request->user()),
            'Modèle archivé.',
        );
    }

    public function restore(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertRouteSite($site);

        return $this->respond(
            $client->restoreDocumentTemplate($site, $documentTemplate, $request->user()),
            'Modèle restauré.',
        );
    }

    public function duplicate(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertRouteSite($site);

        return $this->respond(
            $client->duplicateDocumentTemplate($site, $documentTemplate, $request->user()),
            'Modèle dupliqué.',
        );
    }

    public function history(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): JsonResponse
    {
        $this->assertRouteSite($site);
        $result = $client->documentTemplateHistory($site, $documentTemplate, $request->user());
        abort_unless($result['ok'], 503, $result['message'] ?? 'Le site ne répond pas actuellement.');

        return response()->json(['versions' => $result['data']]);
    }

    public function revert(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertRouteSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $result = $client->revertDocumentTemplateVersion($site, $documentTemplate, $validated['reason'], $request->user());

        if (! $result['ok']) {
            return $this->respond($result, '');
        }

        // The just-edited uuid is now archived; land on the freshly
        // reactivated version's own edit page rather than "back" (which
        // would just re-open that now-archived one, read-only).
        return to_route('super-admin.document-templates.edit', [$site, $result['data']['uuid']])
            ->with('status', $result['message'] ?: 'Version restaurée.');
    }

    public function activate(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->toggle($request, $site, $documentTemplate, true, $client);
    }

    public function deactivate(Request $request, string $site, string $documentTemplate, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->toggle($request, $site, $documentTemplate, false, $client);
    }

    private function toggle(Request $request, string $site, string $documentTemplate, bool $active, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertRouteSite($site);

        return $this->respond(
            $client->setDocumentTemplateActive($site, $documentTemplate, $active, $request->user()),
            $active ? 'Modèle activé.' : 'Modèle désactivé.',
        );
    }

    private function validatedSiteCode(Request $request): string
    {
        return $request->validate([
            'site_code' => ['required', Rule::in($this->siteCodes())],
        ])['site_code'];
    }

    private function assertRouteSite(string $site): void
    {
        abort_unless(in_array(mb_strtoupper($site), $this->siteCodes(), true), 404);
    }

    private function assertSiteReachable(string $site): void
    {
        $configured = collect(config('rivo.clinics', []))->firstWhere('code', mb_strtoupper($site));
        abort_if(
            blank($configured['api_url'] ?? null) || blank($configured['api_token'] ?? null),
            503,
            'L’URL ou le jeton API de ce site n’est pas configuré.',
        );
    }

    /** @return array<int, string> */
    private function siteCodes(): array
    {
        return collect(config('rivo.clinics', []))->pluck('code')->all();
    }

    /** @return array<string, mixed> */
    private function templatePayload(Request $request): array
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:80'],
            'data_context' => ['required', new Enum(DocumentDataContext::class)],
            'applies_to' => ['sometimes', 'nullable', 'array', 'max:30'],
            'applies_to.*' => ['string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'array'],
            'content_html' => ['required', 'string', 'max:8000000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // ADR-244 — envoyé vide plutôt qu'omis : décocher tous les types rend le modèle général.
        $validated['applies_to'] = array_values($validated['applies_to'] ?? []);

        return $validated;
    }

    /**
     * ADR-244 — les types de contrat et de congé du site, qu'un modèle peut viser.
     * Le site injoignable : aucun type, le modèle reste général.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function typeOptions(string $site, Request $request, PortalSiteApiClient $client): array
    {
        $result = $client->documentTemplateTypeOptions(mb_strtoupper($site), $request->user());

        return $result['ok'] ? (array) ($result['data'] ?? []) : [];
    }

    /** @return array<string, string> */
    private function siteMeta(string $siteCode): array
    {
        $site = collect(config('rivo.clinics', []))->firstWhere('code', $siteCode);
        abort_if(! $site, 404);

        return ['code' => $site['code'], 'name' => $site['name']];
    }

    /**
     * ADR-208 — les dossiers de canevas : un par type, avec le contexte attendu.
     *
     * @return list<array{key: string, label: string, context: string}>
     */
    private function familyOptions(): array
    {
        return collect(DocumentFamily::FAMILIES)->map(fn (array $family, string $key) => [
            'key' => $key,
            'label' => $family['label'],
            'context' => $family['context']->value,
        ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function dataContextOptions(): array
    {
        $catalog = app(DocumentFormFieldCatalog::class);

        // ADR-207 — ce que le RH verra : les champs de la page 1, et où le canevas lui est proposé.
        return collect(DocumentDataContext::cases())->map(fn (DocumentDataContext $context) => [
            'value' => $context->value,
            'label' => $context->label(),
            'fields' => collect($catalog->fieldsForContext($context))->pluck('label')->values()->all(),
            'offered_from' => match ($context) {
                DocumentDataContext::EmployeeOnly => ['Documents › le dossier de son type'],
                DocumentDataContext::EmployeeAndContract => ['Documents › Contrats', '« Imprimer » d’un contrat'],
                DocumentDataContext::EmployeeAndLeave => ['Documents › Congés', '« Imprimer » d’un congé'],
            },
        ])->values()->all();
    }

    private function respond(array $result, string $successMessage, ?string $successUrl = null): RedirectResponse
    {
        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [
                    $field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages,
                ],
            )->all();

            return back()->withErrors($errors ?: ['site_code' => $result['message']]);
        }

        return ($successUrl ? redirect($successUrl) : back())->with('status', $result['message'] ?: $successMessage);
    }
}
