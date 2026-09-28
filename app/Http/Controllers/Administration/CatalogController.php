<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Catalog\ArchiveCatalogItemAction;
use App\Actions\Catalog\ArchiveCatalogTariffAction;
use App\Actions\Catalog\CreateCatalogItemAction;
use App\Actions\Catalog\RestoreCatalogItemAction;
use App\Actions\Catalog\ReviewUnlistedPrescriptionLineAction;
use App\Actions\Catalog\SetCatalogTariffAction;
use App\Actions\Catalog\SyncCareActConsumablesAction;
use App\Actions\Catalog\UpdateCatalogItemAction;
use App\Enums\CatalogTariffCategory;
use App\Enums\PrescriptionLineReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveCatalogTariffRequest;
use App\Http\Requests\Administration\CatalogReasonRequest;
use App\Http\Requests\Administration\ReviewUnlistedPrescriptionLineRequest;
use App\Http\Requests\Administration\SetCatalogTariffRequest;
use App\Http\Requests\Administration\StoreCatalogItemRequest;
use App\Http\Requests\Administration\SyncCareActConsumablesRequest;
use App\Http\Requests\Administration\UpdateCatalogItemRequest;
use App\Models\CatalogItem;
use App\Models\PrescriptionLine;
use App\Services\Catalog\CatalogActor;
use App\Services\Catalog\CatalogDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    /**
     * ADR-044, amendement du 2026-09-28 (ter) — le même écran que le portail :
     * les désignations de ce site, par catégorie, leurs deux tarifs, et les
     * médicaments prescrits hors référentiel qui attendent d'être traités.
     */
    public function index(Request $request, CatalogDirectory $directory): Response
    {
        $user = $request->user();
        $site = ['code' => (string) config('rivo.site.code'), 'name' => (string) config('rivo.site.name')];

        return Inertia::render('Catalog/Index', [
            'context' => ['mode' => 'site'],
            // La forme du portail, réduite à ce site : un seul écran les lit tous deux.
            'sites' => [[
                'site' => $site,
                'ok' => true,
                'message' => null,
                // Les mutuelles se règlent au portail (ADR-045) : aucune route ici.
                'data' => $directory->listing(['status' => 'ALL'], $user->can('catalog.tariffs.view'), false),
            ]],
            'selectedSiteCode' => $site['code'],
            'pendingMedicines' => $user->can('catalog.items.create') ? $this->pendingUnlistedMedicines() : [],
        ]);
    }

    /** La page « Nouvelle désignation », rangée d'avance dans la catégorie d'où l'on vient. */
    public function create(Request $request, CatalogDirectory $directory): Response
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z_]+(:[A-Z_]+)?$/'],
        ]);

        return Inertia::render('Catalog/ItemForm', [
            'context' => ['mode' => 'site'],
            'targetSite' => ['code' => (string) config('rivo.site.code'), 'name' => (string) config('rivo.site.name')],
            'item' => null,
            'options' => $directory->options(),
            'consumableOptions' => [],
            'category' => $validated['module'] ?? null,
            'siteError' => null,
        ]);
    }

    /** La fiche d'une désignation, archivée comprise : ce qui la décrit, ses deux tarifs, son matériel habituel. */
    public function edit(Request $request, string $catalogItem, CatalogDirectory $directory): Response
    {
        $item = CatalogItem::withTrashed()->where('uuid', $catalogItem)->firstOrFail();
        $data = $directory->item($item, $request->user()->can('catalog.tariffs.view'), $request->user()->can('catalog.items.update'));

        return Inertia::render('Catalog/ItemForm', [
            'context' => ['mode' => 'site'],
            'targetSite' => ['code' => (string) config('rivo.site.code'), 'name' => (string) config('rivo.site.name')],
            'item' => $data['item'],
            'options' => $data['options'],
            'consumableOptions' => $data['consumable_options'],
            'category' => null,
            'siteError' => null,
        ]);
    }

    /**
     * ADR-072 — configures the material a nursing act usually consumes, so
     * that Soins gets a coherent pre-selection instead of searching the
     * whole pharmacy list. The clinical content of these associations is a
     * decision for whoever administers the catalogue; nothing is inferred
     * from an act's name or code.
     */
    public function syncCareConsumables(
        SyncCareActConsumablesRequest $request,
        CatalogItem $catalogItem,
        SyncCareActConsumablesAction $action,
    ): RedirectResponse {
        $action->execute(
            $catalogItem,
            $request->validated('consumables', []),
            CatalogActor::fromUser($request->user()),
        );

        return back()->with('status', "Matériel habituel de {$catalogItem->name} mis à jour.");
    }

    public function store(StoreCatalogItemRequest $request, CreateCatalogItemAction $action): RedirectResponse
    {
        $item = $action->execute($request->validated(), CatalogActor::fromUser($request->user()));

        // Retour à la catégorie de la nouvelle désignation, ouverte sur son code.
        return redirect('/administration/catalog?'.http_build_query(array_filter([
            'module' => CatalogDirectory::categoryOf([
                'module' => $item->module->value,
                'imaging_modality' => $item->imaging_modality?->value,
            ]),
            'q' => $item->code,
        ])))->with('status', "Désignation {$item->code} créée.");
    }

    public function update(
        UpdateCatalogItemRequest $request,
        CatalogItem $catalogItem,
        UpdateCatalogItemAction $action,
    ): RedirectResponse {
        $action->execute($catalogItem, $request->validated(), CatalogActor::fromUser($request->user()));

        return back()->with('status', "Désignation {$catalogItem->code} mise à jour.");
    }

    public function setTariff(
        SetCatalogTariffRequest $request,
        CatalogItem $catalogItem,
        SetCatalogTariffAction $action,
    ): RedirectResponse {
        $category = CatalogTariffCategory::from($request->validated('tariff_category'));
        $action->execute(
            $catalogItem,
            $category,
            $request->validated('tariff_amount'),
            $request->validated('reason'),
            CatalogActor::fromUser($request->user()),
        );

        return back()->with('status', "Tarif {$category->label()} enregistré pour {$catalogItem->code}.");
    }

    public function archiveTariff(
        ArchiveCatalogTariffRequest $request,
        CatalogItem $catalogItem,
        ArchiveCatalogTariffAction $action,
    ): RedirectResponse {
        $category = CatalogTariffCategory::from($request->validated('tariff_category'));
        $action->execute(
            $catalogItem,
            $category,
            $request->validated('reason'),
            CatalogActor::fromUser($request->user()),
        );

        return back()->with('status', "Tarif {$category->label()} de {$catalogItem->code} suspendu.");
    }

    public function destroy(
        CatalogReasonRequest $request,
        CatalogItem $catalogItem,
        ArchiveCatalogItemAction $action,
    ): RedirectResponse {
        $action->execute(
            $catalogItem,
            $request->validated('reason'),
            CatalogActor::fromUser($request->user()),
        );

        return back()->with('status', "Désignation {$catalogItem->code} archivée.");
    }

    public function restore(Request $request, string $catalogItem, RestoreCatalogItemAction $action): RedirectResponse
    {
        $item = CatalogItem::onlyTrashed()->where('uuid', $catalogItem)->firstOrFail();
        $action->execute($item, CatalogActor::fromUser($request->user()));

        return back()->with('status', "Désignation {$item->code} restaurée.");
    }

    public function reviewUnlistedMedicine(
        ReviewUnlistedPrescriptionLineRequest $request,
        PrescriptionLine $prescriptionLine,
        ReviewUnlistedPrescriptionLineAction $action,
    ): RedirectResponse {
        $action->execute($prescriptionLine, $request->validated('note'), $request->user());

        return back()->with('status', "Demande « {$prescriptionLine->medication_name} » traitée.");
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingUnlistedMedicines(): array
    {
        return PrescriptionLine::query()
            ->where('is_manual_entry', true)
            ->where('catalog_review_status', PrescriptionLineReviewStatus::Pending->value)
            ->with([
                'prescription.prescribedBy:id,name',
                'prescription.episode:id,episode_number,patient_id',
                'prescription.episode.patient:id,patient_number',
            ])
            ->latest('created_at')
            ->get()
            ->map(fn (PrescriptionLine $line) => [
                'id' => $line->getKey(),
                'medication_name' => $line->medication_name,
                'quantity' => $line->quantity,
                'dosage' => $line->dosage,
                'frequency' => $line->frequency,
                'duration' => $line->duration,
                'instructions' => $line->instructions,
                'prescribed_at' => $line->prescription->prescribed_at ?? $line->created_at,
                'prescribed_by' => $line->prescription->prescribedBy?->name,
                'episode_number' => $line->prescription->episode?->episode_number,
                'patient_number' => $line->prescription->episode?->patient?->patient_number,
            ])
            ->values()
            ->all();
    }
}
