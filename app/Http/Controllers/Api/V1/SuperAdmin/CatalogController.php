<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Catalog\ArchiveCatalogItemAction;
use App\Actions\Catalog\ArchiveCatalogTariffAction;
use App\Actions\Catalog\CreateCatalogItemAction;
use App\Actions\Catalog\RestoreCatalogItemAction;
use App\Actions\Catalog\SetCatalogTariffAction;
use App\Actions\Catalog\UpdateCatalogItemAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CatalogReasonRequest;
use App\Http\Requests\Api\V1\SuperAdmin\ArchiveCatalogTariffRequest;
use App\Http\Requests\Api\V1\SuperAdmin\SetCatalogTariffRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreCatalogItemRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateCatalogItemRequest;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\MutualOrganization;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'catalog.items.view');
        $canViewTariffs = $actor->can('catalog.tariffs.view');
        $canViewMutualOrganizations = $actor->can('mutual_organizations.view');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
            'type' => ['nullable', new Enum(CatalogItemType::class)],
            'module' => ['nullable', new Enum(CatalogModule::class)],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $status = $validated['status'] ?? 'ALL';

        $query = CatalogItem::query()
            ->when($canViewTariffs, fn ($query) => $query->with([
                'currentStandardTariff.creator:id,name',
                'currentMutualTariff.creator:id,name',
                'tariffs' => fn ($query) => $query->with('creator:id,name')->latest('effective_from')->limit(20),
            ])->withCount('tariffs'))
            ->when($status === 'ARCHIVED', fn ($query) => $query->onlyTrashed())
            ->when($status === 'ALL', fn ($query) => $query->withTrashed())
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->when(filled($validated['type'] ?? null), fn ($query) => $query->where('type', $validated['type']))
            ->when(filled($validated['module'] ?? null), fn ($query) => $query->where('module', $validated['module']))
            ->orderBy('name');

        return response()->json([
            'data' => [
                'items' => $query->get()->map(fn (CatalogItem $item) => $this->serializeItem($item, $canViewTariffs))->values(),
                'summary' => $this->summary($canViewTariffs),
                'options' => $this->options(),
                'mutual_organizations' => $canViewMutualOrganizations
                    ? MutualOrganization::withTrashed()
                        ->withCount([
                            'coverages',
                            'coverages as active_coverages_count' => fn ($query) => $query->active(),
                        ])
                        ->orderBy('normalized_name')
                        ->get()
                        ->map(fn (MutualOrganization $organization) => $this->serializeOrganization($organization))
                        ->values()
                    : [],
                'mutual_organizations_summary' => $canViewMutualOrganizations
                    ? $this->mutualOrganizationsSummary()
                    : null,
            ],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function store(
        StoreCatalogItemRequest $request,
        CreateCatalogItemAction $action,
    ): JsonResponse {
        $item = $action->execute(
            $request->validated(),
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json([
            'message' => "Désignation {$item->code} créée sur le site.",
            'data' => $this->serializeItem($this->loadItem($item), true),
        ], 201);
    }

    public function importTariffs(Request $request, SetCatalogTariffAction $action): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'catalog.tariffs.import');
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.code' => ['required', 'string', 'max:60'],
            'rows.*.standard_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'rows.*.mutual_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'rows.*.reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $rows = collect($validated['rows'])->map(function (array $row): array {
            $row['code'] = mb_strtoupper(trim($row['code']));

            if (! filled($row['standard_amount'] ?? null) && ! filled($row['mutual_amount'] ?? null)) {
                throw ValidationException::withMessages([
                    'rows' => "Aucun tarif n’est renseigné pour {$row['code']}.",
                ]);
            }

            return $row;
        });

        if ($rows->pluck('code')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'rows' => 'Le fichier contient plusieurs lignes pour la même désignation.',
            ]);
        }

        $result = DB::transaction(function () use ($rows, $actor, $action): array {
            $items = CatalogItem::query()
                ->whereIn('code', $rows->pluck('code'))
                ->where('billable', true)
                ->with(['currentStandardTariff', 'currentMutualTariff'])
                ->lockForUpdate()
                ->get()
                ->keyBy('code');

            if ($items->count() !== $rows->count()) {
                $missing = $rows->pluck('code')->diff($items->keys())->implode(', ');

                throw ValidationException::withMessages([
                    'rows' => "Désignation(s) facturable(s) active(s) introuvable(s) : {$missing}.",
                ]);
            }

            $changed = 0;
            $unchanged = 0;

            foreach ($rows as $row) {
                $item = $items->get($row['code']);

                foreach ([
                    CatalogTariffCategory::Standard->value => 'standard_amount',
                    CatalogTariffCategory::Mutual->value => 'mutual_amount',
                ] as $categoryValue => $field) {
                    if (! filled($row[$field] ?? null)) {
                        continue;
                    }

                    $category = CatalogTariffCategory::from($categoryValue);
                    $current = $category === CatalogTariffCategory::Standard
                        ? $item->currentStandardTariff
                        : $item->currentMutualTariff;

                    if ($current && Money::toMinor($current->amount) === Money::toMinor((string) $row[$field])) {
                        $unchanged++;

                        continue;
                    }

                    $action->execute($item, $category, (string) $row[$field], $row['reason'], $actor);
                    $changed++;
                }
            }

            return compact('changed', 'unchanged');
        });

        return response()->json([
            'message' => sprintf(
                'Import terminé : %d tarif(s) versionné(s), %d inchangé(s).',
                $result['changed'],
                $result['unchanged'],
            ),
            'data' => $result,
        ]);
    }

    public function update(
        UpdateCatalogItemRequest $request,
        string $catalogUuid,
        UpdateCatalogItemAction $action,
    ): JsonResponse {
        $item = CatalogItem::query()->where('uuid', $catalogUuid)->firstOrFail();
        $item = $action->execute(
            $item,
            $request->validated(),
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json([
            'message' => "Désignation {$item->code} mise à jour.",
            'data' => $this->serializeItem($this->loadItem($item), true),
        ]);
    }

    public function destroy(
        CatalogReasonRequest $request,
        string $catalogUuid,
        ArchiveCatalogItemAction $action,
    ): JsonResponse {
        $item = CatalogItem::query()->where('uuid', $catalogUuid)->firstOrFail();
        $action->execute(
            $item,
            $request->validated('reason'),
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => "Désignation {$item->code} archivée."]);
    }

    public function restore(
        Request $request,
        string $catalogUuid,
        RestoreCatalogItemAction $action,
    ): JsonResponse {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'trash.restore');
        $item = CatalogItem::onlyTrashed()->where('uuid', $catalogUuid)->firstOrFail();
        $item = $action->execute($item, $actor);

        return response()->json([
            'message' => "Désignation {$item->code} restaurée.",
            'data' => $this->serializeItem($this->loadItem($item), true),
        ]);
    }

    public function bulkArchive(
        Request $request,
        ArchiveCatalogItemAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'catalog.items.delete');

        $count = DB::transaction(function () use ($validated, $actor, $action): int {
            $items = CatalogItem::query()
                ->whereIn('uuid', $validated['uuids'])
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Une désignation sélectionnée est absente ou déjà archivée. Aucune modification n’a été appliquée.',
                ]);
            }

            $items->each(fn (CatalogItem $item) => $action->execute($item, $validated['reason'], $actor));

            return $items->count();
        });

        return response()->json([
            'message' => "{$count} désignation(s) archivée(s).",
            'data' => ['processed' => $count],
        ]);
    }

    public function bulkRestore(
        Request $request,
        RestoreCatalogItemAction $action,
    ): JsonResponse {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'trash.restore');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $this->authorizeActor($actor, 'catalog.items.restore');

        $count = DB::transaction(function () use ($validated, $actor, $action): int {
            $items = CatalogItem::onlyTrashed()
                ->whereIn('uuid', $validated['uuids'])
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Une désignation sélectionnée est absente ou déjà active. Aucune modification n’a été appliquée.',
                ]);
            }

            $items->each(fn (CatalogItem $item) => $action->execute($item, $actor));

            return $items->count();
        });

        return response()->json([
            'message' => "{$count} désignation(s) restaurée(s).",
            'data' => ['processed' => $count],
        ]);
    }

    public function setTariff(
        SetCatalogTariffRequest $request,
        string $catalogUuid,
        SetCatalogTariffAction $action,
    ): JsonResponse {
        $item = CatalogItem::query()->where('uuid', $catalogUuid)->firstOrFail();
        $category = CatalogTariffCategory::from($request->validated('tariff_category'));
        $action->execute(
            $item,
            $category,
            $request->validated('tariff_amount'),
            $request->validated('reason'),
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json([
            'message' => "Tarif {$category->label()} enregistré pour {$item->code}.",
            'data' => $this->serializeItem($this->loadItem($item), true),
        ]);
    }

    public function archiveTariff(
        ArchiveCatalogTariffRequest $request,
        string $catalogUuid,
        ArchiveCatalogTariffAction $action,
    ): JsonResponse {
        $item = CatalogItem::query()->where('uuid', $catalogUuid)->firstOrFail();
        $category = CatalogTariffCategory::from($request->validated('tariff_category'));
        $action->execute(
            $item,
            $category,
            $request->validated('reason'),
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json([
            'message' => "Tarif {$category->label()} de {$item->code} suspendu.",
            'data' => $this->serializeItem($this->loadItem($item), true),
        ]);
    }

    private function authorizeActor(CatalogActor $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }

    private function loadItem(CatalogItem $item): CatalogItem
    {
        return $item->fresh([
            'currentStandardTariff.creator:id,name',
            'currentMutualTariff.creator:id,name',
            'tariffs' => fn ($query) => $query->with('creator:id,name')->latest('effective_from')->limit(20),
        ])->loadCount('tariffs');
    }

    /** @return array<string, int|null> */
    private function summary(bool $canViewTariffs): array
    {
        return [
            'active' => CatalogItem::query()->count(),
            'archived' => CatalogItem::onlyTrashed()->count(),
            'billable' => CatalogItem::query()->where('billable', true)->count(),
            'without_standard_tariff' => $canViewTariffs
                ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentStandardTariff')->count()
                : null,
            'without_mutual_tariff' => $canViewTariffs
                ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentMutualTariff')->count()
                : null,
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function options(): array
    {
        return [
            'types' => collect(CatalogItemType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'billable' => $type->mustBeBillable(),
                'stockable' => $type->mustBeStockable(),
            ])->all(),
            'modules' => collect(CatalogModule::cases())->map(fn ($module) => [
                'value' => $module->value,
                'label' => $module->label(),
            ])->all(),
            'routing_modes' => collect(ReceptionRoutingMode::cases())->map(fn ($mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
            ])->all(),
            'staff_coverage_policies' => collect(StaffCoveragePolicy::cases())->map(fn ($policy) => [
                'value' => $policy->value,
                'label' => $policy->label(),
            ])->all(),
            'tariff_categories' => collect(CatalogTariffCategory::cases())->map(fn ($category) => [
                'value' => $category->value,
                'label' => $category->label(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeItem(CatalogItem $item, bool $canViewTariffs): array
    {
        $standard = $canViewTariffs ? $item->currentStandardTariff : null;
        $mutual = $canViewTariffs ? $item->currentMutualTariff : null;

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
            'staff_coverage_policy' => $item->staff_coverage_policy->value,
            'staff_coverage_policy_label' => $item->staff_coverage_policy->label(),
            'care_requires_allergy_check' => $item->care_requires_allergy_check,
            'care_recommends_vitals' => $item->care_recommends_vitals,
            'description' => $item->description,
            'archived' => $item->trashed(),
            'archived_at' => $item->deleted_at?->toIso8601String(),
            'archive_reason' => $item->delete_reason,
            'current_standard_tariff' => $standard ? $this->serializeTariff($standard) : null,
            'current_mutual_tariff' => $mutual ? $this->serializeTariff($mutual) : null,
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
            'effective_from' => $tariff->effective_from?->toIso8601String(),
            'effective_until' => $tariff->effective_until?->toIso8601String(),
            'change_reason' => $tariff->change_reason,
            'current' => $tariff->isCurrent(),
            'creator' => $tariff->creator?->name ?? $tariff->external_created_by_name,
        ];
    }

    /** @return array<string, int> */
    private function mutualOrganizationsSummary(): array
    {
        return [
            'active' => MutualOrganization::query()->count(),
            'archived' => MutualOrganization::onlyTrashed()->count(),
            'active_coverages' => (int) MutualOrganization::query()
                ->withCount(['coverages as active_coverages_count' => fn ($query) => $query->active()])
                ->get()
                ->sum('active_coverages_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeOrganization(MutualOrganization $organization): array
    {
        return [
            'uuid' => $organization->uuid,
            'name' => $organization->name,
            'coverage_rate' => $organization->coverage_rate,
            'patient_rate' => Money::fromMinor(10_000 - Money::toMinor($organization->coverage_rate)),
            'active' => ! $organization->trashed() && $organization->active,
            'coverages_count' => (int) ($organization->coverages_count ?? 0),
            'active_coverages_count' => (int) ($organization->active_coverages_count ?? 0),
            'archived_at' => $organization->deleted_at?->toIso8601String(),
            'archive_reason' => $organization->delete_reason,
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }
}
