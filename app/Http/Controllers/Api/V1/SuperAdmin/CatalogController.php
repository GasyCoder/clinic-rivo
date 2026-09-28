<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Catalog\ArchiveCatalogItemAction;
use App\Actions\Catalog\ArchiveCatalogTariffAction;
use App\Actions\Catalog\CreateCatalogItemAction;
use App\Actions\Catalog\RestoreCatalogItemAction;
use App\Actions\Catalog\SetCatalogTariffAction;
use App\Actions\Catalog\SyncCareActConsumablesAction;
use App\Actions\Catalog\UpdateCatalogItemAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CatalogReasonRequest;
use App\Http\Requests\Api\V1\SuperAdmin\ArchiveCatalogTariffRequest;
use App\Http\Requests\Api\V1\SuperAdmin\SetCatalogTariffRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreCatalogItemRequest;
use App\Http\Requests\Api\V1\SuperAdmin\SyncCareActConsumablesRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateCatalogItemRequest;
use App\Models\CatalogItem;
use App\Services\Catalog\CatalogActor;
use App\Services\Catalog\CatalogDirectory;
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
    public function __construct(private readonly CatalogDirectory $directory) {}

    public function index(Request $request): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'catalog.items.view');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
            'type' => ['nullable', new Enum(CatalogItemType::class)],
            'module' => ['nullable', new Enum(CatalogModule::class)],
        ]);

        return response()->json([
            'data' => $this->directory->listing(
                $validated,
                $actor->can('catalog.tariffs.view'),
                $actor->can('mutual_organizations.view'),
            ),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Les listes de choix du formulaire d'une désignation (types, domaines,
     * familles d'imagerie, parcours, politiques Personnel, grilles), sans le
     * catalogue entier : la page « Nouvelle désignation » du portail n'a besoin
     * que d'elles.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $this->authorizeActor(CatalogActor::fromRemoteRequest($request), 'catalog.items.view');

        return response()->json([
            'data' => ['options' => $this->directory->options()],
            'meta' => ['site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')]],
        ]);
    }

    /**
     * Une désignation, archivée comprise, avec son historique tarifaire, les
     * listes de choix et — pour qui peut le régler — les produits proposables
     * comme matériel habituel.
     */
    public function show(Request $request, string $catalogUuid): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        $this->authorizeActor($actor, 'catalog.items.view');
        $item = CatalogItem::withTrashed()->where('uuid', $catalogUuid)->firstOrFail();

        return response()->json([
            'data' => $this->directory->item($item, $actor->can('catalog.tariffs.view'), $actor->can('catalog.items.update')),
            'meta' => ['site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')]],
        ]);
    }

    /**
     * ADR-072 / ADR-142 / ADR-169 — le matériel habituel d'un acte, réglé depuis
     * le portail : la même action que sur le site, attribuée au Super Admin.
     */
    public function syncCareConsumables(
        SyncCareActConsumablesRequest $request,
        string $catalogUuid,
        SyncCareActConsumablesAction $action,
    ): JsonResponse {
        $item = CatalogItem::query()->where('uuid', $catalogUuid)->firstOrFail();
        $item = $action->execute($item, $request->validated('consumables', []), CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "Matériel habituel de {$item->name} mis à jour.",
            'data' => $this->directory->serializeItem($this->directory->load($item), true),
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
            'data' => $this->directory->serializeItem($this->directory->load($item), true),
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
            'data' => $this->directory->serializeItem($this->directory->load($item), true),
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
            'data' => $this->directory->serializeItem($this->directory->load($item), true),
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
            'data' => $this->directory->serializeItem($this->directory->load($item), true),
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
            'data' => $this->directory->serializeItem($this->directory->load($item), true),
        ]);
    }

    private function authorizeActor(CatalogActor $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
