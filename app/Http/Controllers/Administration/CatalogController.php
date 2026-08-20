<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Catalog\ArchiveCatalogItemAction;
use App\Actions\Catalog\ArchiveCatalogTariffAction;
use App\Actions\Catalog\CreateCatalogItemAction;
use App\Actions\Catalog\RestoreCatalogItemAction;
use App\Actions\Catalog\SetCatalogTariffAction;
use App\Actions\Catalog\UpdateCatalogItemAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CatalogReasonRequest;
use App\Http\Requests\Administration\SetCatalogTariffRequest;
use App\Http\Requests\Administration\StoreCatalogItemRequest;
use App\Http\Requests\Administration\UpdateCatalogItemRequest;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
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
                    'tariffs' => fn ($query) => $query->with('creator:id,name')->latest('effective_from')->limit(8),
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
            'summary' => [
                'active' => CatalogItem::query()->count(),
                'archived' => CatalogItem::onlyTrashed()->count(),
                'billable' => CatalogItem::query()->where('billable', true)->count(),
                'without_tariff' => $canViewTariffs
                    ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentTariff')->count()
                    : null,
            ],
        ]);
    }

    public function store(StoreCatalogItemRequest $request, CreateCatalogItemAction $action): RedirectResponse
    {
        $item = $action->execute($request->validated(), $request->user());

        return back()->with('status', "Élément {$item->code} créé dans le référentiel.");
    }

    public function update(
        UpdateCatalogItemRequest $request,
        CatalogItem $catalogItem,
        UpdateCatalogItemAction $action,
    ): RedirectResponse {
        $action->execute($catalogItem, $request->validated(), $request->user());

        return back()->with('status', "Élément {$catalogItem->code} mis à jour.");
    }

    public function setTariff(
        SetCatalogTariffRequest $request,
        CatalogItem $catalogItem,
        SetCatalogTariffAction $action,
    ): RedirectResponse {
        $action->execute(
            $catalogItem,
            $request->validated('tariff_amount'),
            $request->validated('reason'),
            $request->user(),
        );

        return back()->with('status', "Nouveau tarif enregistré pour {$catalogItem->code}.");
    }

    public function archiveTariff(
        CatalogReasonRequest $request,
        CatalogItem $catalogItem,
        ArchiveCatalogTariffAction $action,
    ): RedirectResponse {
        $action->execute($catalogItem, $request->validated('reason'), $request->user());

        return back()->with('status', "Tarif de {$catalogItem->code} suspendu.");
    }

    public function destroy(
        CatalogReasonRequest $request,
        CatalogItem $catalogItem,
        ArchiveCatalogItemAction $action,
    ): RedirectResponse {
        $action->execute($catalogItem, $request->validated('reason'), $request->user());

        return back()->with('status', "Élément {$catalogItem->code} archivé.");
    }

    public function restore(Request $request, string $catalogItem, RestoreCatalogItemAction $action): RedirectResponse
    {
        $item = CatalogItem::onlyTrashed()->where('uuid', $catalogItem)->firstOrFail();
        $action->execute($item, $request->user());

        return back()->with('status', "Élément {$item->code} restauré.");
    }

    /** @return array<string, mixed> */
    private function serializeItem(CatalogItem $item, bool $canViewTariffs): array
    {
        $currentTariff = $canViewTariffs
            ? $item->tariffs->first(fn (CatalogTariff $tariff) => $tariff->isCurrent())
            : null;

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
            'description' => $item->description,
            'archived' => $item->trashed(),
            'archived_at' => $item->deleted_at,
            'archive_reason' => $item->delete_reason,
            'current_tariff' => $currentTariff ? $this->serializeTariff($currentTariff) : null,
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
