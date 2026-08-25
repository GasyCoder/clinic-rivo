<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Catalog\ArchiveCatalogItemAction;
use App\Actions\Catalog\ArchiveCatalogTariffAction;
use App\Actions\Catalog\CreateCatalogItemAction;
use App\Actions\Catalog\RestoreCatalogItemAction;
use App\Actions\Catalog\ReviewUnlistedPrescriptionLineAction;
use App\Actions\Catalog\SetCatalogTariffAction;
use App\Actions\Catalog\UpdateCatalogItemAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\PrescriptionLineReviewStatus;
use App\Enums\ReceptionRoutingMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveCatalogTariffRequest;
use App\Http\Requests\Administration\CatalogReasonRequest;
use App\Http\Requests\Administration\ReviewUnlistedPrescriptionLineRequest;
use App\Http\Requests\Administration\SetCatalogTariffRequest;
use App\Http\Requests\Administration\StoreCatalogItemRequest;
use App\Http\Requests\Administration\UpdateCatalogItemRequest;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\PrescriptionLine;
use App\Services\Catalog\CatalogActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $type = CatalogItemType::tryFrom((string) $request->query('type'));
        $module = CatalogModule::tryFrom((string) $request->query('module'));
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true)
            ? $request->query('status')
            : 'active';
        $canViewTariffs = $request->user()->can('catalog.tariffs.view');

        $query = CatalogItem::query()
            ->when($canViewTariffs, fn ($query) => $query
                ->with([
                    'currentStandardTariff.creator:id,name',
                    'currentMutualTariff.creator:id,name',
                    'tariffs' => fn ($query) => $query->with('creator:id,name')->latest('effective_from')->limit(16),
                ])
                ->withCount('tariffs'))
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->when($type, fn ($query) => $query->where('type', $type->value))
            ->when($module, fn ($query) => $query->where('module', $module->value))
            ->orderBy('name');

        $items = $query->paginate(20)->withQueryString()->through(
            fn (CatalogItem $item) => $this->serializeItem($item, $canViewTariffs),
        );

        return Inertia::render('Administration/Catalog/Index', [
            'items' => $items,
            'pendingMedicines' => $this->pendingUnlistedMedicines(),
            'filters' => [
                'q' => $search,
                'type' => $type?->value ?? '',
                'module' => $module?->value ?? '',
                'status' => $status,
            ],
            'types' => collect(CatalogItemType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'billable' => $type->mustBeBillable(),
                'stockable' => $type->mustBeStockable(),
            ]),
            'modules' => collect(CatalogModule::cases())->map(fn ($module) => [
                'value' => $module->value,
                'label' => $module->label(),
            ]),
            'receptionRoutingModes' => collect(ReceptionRoutingMode::cases())->map(fn ($mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
            ]),
            'tariffCategories' => collect(CatalogTariffCategory::cases())->map(fn ($category) => [
                'value' => $category->value,
                'label' => $category->label(),
            ]),
            'summary' => [
                'active' => CatalogItem::query()->count(),
                'archived' => CatalogItem::onlyTrashed()->count(),
                'billable' => CatalogItem::query()->where('billable', true)->count(),
                'without_tariff' => $canViewTariffs
                    ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentStandardTariff')->count()
                    : null,
                'without_standard_tariff' => $canViewTariffs
                    ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentStandardTariff')->count()
                    : null,
                'without_mutual_tariff' => $canViewTariffs
                    ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentMutualTariff')->count()
                    : null,
            ],
        ]);
    }

    public function store(StoreCatalogItemRequest $request, CreateCatalogItemAction $action): RedirectResponse
    {
        $item = $action->execute($request->validated(), CatalogActor::fromUser($request->user()));

        return back()->with('status', "Élément {$item->code} créé dans le référentiel.");
    }

    public function update(
        UpdateCatalogItemRequest $request,
        CatalogItem $catalogItem,
        UpdateCatalogItemAction $action,
    ): RedirectResponse {
        $action->execute($catalogItem, $request->validated(), CatalogActor::fromUser($request->user()));

        return back()->with('status', "Élément {$catalogItem->code} mis à jour.");
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

        return back()->with('status', "Élément {$catalogItem->code} archivé.");
    }

    public function restore(Request $request, string $catalogItem, RestoreCatalogItemAction $action): RedirectResponse
    {
        $item = CatalogItem::onlyTrashed()->where('uuid', $catalogItem)->firstOrFail();
        $action->execute($item, CatalogActor::fromUser($request->user()));

        return back()->with('status', "Élément {$item->code} restauré.");
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
                'prescription.consultation.episode:id,episode_number,patient_id',
                'prescription.consultation.episode.patient:id,patient_number',
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
                'episode_number' => $line->prescription->consultation?->episode?->episode_number,
                'patient_number' => $line->prescription->consultation?->episode?->patient?->patient_number,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function serializeItem(CatalogItem $item, bool $canViewTariffs): array
    {
        $currentStandardTariff = $canViewTariffs ? $item->currentStandardTariff : null;
        $currentMutualTariff = $canViewTariffs ? $item->currentMutualTariff : null;

        return [
            'uuid' => $item->uuid,
            'code' => $item->code,
            'name' => $item->name,
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'module' => $item->module->value,
            'module_label' => $item->module->label(),
            'unit' => $item->unit,
            'billable' => $item->billable,
            'stockable' => $item->stockable,
            'reception_selectable' => $item->reception_selectable,
            'reception_routing_mode' => $item->reception_routing_mode?->value,
            'reception_routing_label' => $item->reception_routing_mode?->label(),
            'care_requires_allergy_check' => $item->care_requires_allergy_check,
            'care_recommends_vitals' => $item->care_recommends_vitals,
            'description' => $item->description,
            'archived' => $item->trashed(),
            'archived_at' => $item->deleted_at,
            'archive_reason' => $item->delete_reason,
            // `current_tariff` remains as a compatibility alias for the
            // former single tariff representation.
            'current_tariff' => $currentStandardTariff ? $this->serializeTariff($currentStandardTariff) : null,
            'current_standard_tariff' => $currentStandardTariff ? $this->serializeTariff($currentStandardTariff) : null,
            'current_mutual_tariff' => $currentMutualTariff ? $this->serializeTariff($currentMutualTariff) : null,
            'tariffs_count' => $canViewTariffs ? $item->tariffs_count : null,
            'tariffs' => $canViewTariffs
                ? $item->tariffs->map(fn (CatalogTariff $tariff) => $this->serializeTariff($tariff))->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTariff(CatalogTariff $tariff): array
    {
        return [
            'uuid' => $tariff->uuid,
            'tariff_category' => $tariff->tariff_category->value,
            'tariff_category_label' => $tariff->tariff_category->label(),
            'amount' => $tariff->amount,
            'currency' => $tariff->currency,
            'effective_from' => $tariff->effective_from,
            'effective_until' => $tariff->effective_until,
            'change_reason' => $tariff->change_reason,
            'current' => $tariff->isCurrent(),
            'creator' => $tariff->creator?->name,
        ];
    }
}
