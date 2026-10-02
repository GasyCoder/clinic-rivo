<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Pharmacy\DecideSupplierProductEquivalenceAction;
use App\Actions\Pharmacy\SaveProductSynonymAction;
use App\Enums\SupplierEquivalenceStatus;
use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\ProductSynonym;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierProductEquivalence;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ADR-241 — le dictionnaire des abréviations et les décisions « même produit
 * / deux produits » d'un site, réglés depuis le portail. Le site revérifie le
 * droit de l'acteur distant et l'attribue dans l'audit.
 */
class SupplierEquivalenceController extends Controller
{
    /** Au-delà, une réponse de l'IA n'est plus une liste qu'on relit. */
    public const MAX_PROPOSALS = 100;

    public function synonyms(Request $request, SaveProductSynonymAction $action): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json(['data' => $action->present()]);
    }

    public function storeSynonym(Request $request, SaveProductSynonymAction $action): JsonResponse
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:40'],
            'canonical' => ['required', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $synonym = $action->execute($data['term'], $data['canonical'], $data['note'] ?? null, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "« {$synonym->term} » se lit désormais « {$synonym->canonical} ».",
            'data' => $action->present(),
        ]);
    }

    public function destroySynonym(Request $request, string $synonymUuid, SaveProductSynonymAction $action): JsonResponse
    {
        $synonym = ProductSynonym::query()->where('uuid', $synonymUuid)->firstOrFail();
        $action->delete($synonym, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Abréviation « {$synonym->term} » retirée.", 'data' => $action->present()]);
    }

    public function decisions(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        $rows = SupplierProductEquivalence::query()
            ->with(['supplier:id,uuid,name', 'otherSupplier:id,uuid,name', 'medicine.catalogItem:id,name,code', 'decider:id,name'])
            ->latest('updated_at')
            ->limit(300)
            ->get()
            ->map(fn (SupplierProductEquivalence $row) => [
                'uuid' => $row->uuid,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'source' => $row->source,
                'supplier_name' => $row->supplier?->name,
                'label' => $row->label,
                'other_supplier_name' => $row->otherSupplier?->name,
                'other_label' => $row->other_label ?? $row->medicine?->catalogItem?->name,
                'against_clinic' => $row->medicine_id !== null,
                'reason' => $row->reason,
                'decided_by' => $row->decider?->name ?? $row->external_decided_by_name,
                'decided_at' => ($row->decided_at ?? $row->updated_at)?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function decide(Request $request, DecideSupplierProductEquivalenceAction $action): JsonResponse
    {
        $data = $request->validate([
            'item_uuid' => ['required', 'uuid'],
            'status' => ['required', Rule::in([SupplierEquivalenceStatus::Same->value, SupplierEquivalenceStatus::Different->value])],
            'other_item_uuids' => ['nullable', 'array', 'max:50'],
            'other_item_uuids.*' => ['uuid'],
            'medicine_uuids' => ['nullable', 'array', 'max:10'],
            'medicine_uuids.*' => ['uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $item = $this->item($data['item_uuid']);
        $others = collect($data['other_item_uuids'] ?? [])->unique()->map(fn (string $uuid) => $this->item($uuid))->values();
        $medicines = Medicine::query()->whereIn('uuid', $data['medicine_uuids'] ?? [])->get();

        if ($medicines->count() !== count(array_unique($data['medicine_uuids'] ?? []))) {
            throw ValidationException::withMessages(['medicine_uuids' => 'Un des produits de la clinique est introuvable.']);
        }

        $status = SupplierEquivalenceStatus::from($data['status']);
        $count = $action->execute($item, $status, $others, $medicines, $data['reason'] ?? null, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => $status === SupplierEquivalenceStatus::Same
                ? 'Mémorisé : c’est le même produit. Leurs prix se comparent sur une seule ligne.'
                : 'Mémorisé : ce sont deux produits. Ils ne seront plus rapprochés.',
            'data' => ['written' => $count],
        ]);
    }

    public function propose(Request $request, DecideSupplierProductEquivalenceAction $action): JsonResponse
    {
        $data = $request->validate([
            'pairs' => ['required', 'array', 'min:1', 'max:'.self::MAX_PROPOSALS],
            'pairs.*.item_uuid' => ['required', 'uuid'],
            'pairs.*.other_item_uuid' => ['required', 'uuid', 'different:pairs.*.item_uuid'],
            'pairs.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        $items = SupplierCatalogItem::query()
            ->whereIn('uuid', collect($data['pairs'])->flatMap(fn (array $pair) => [$pair['item_uuid'], $pair['other_item_uuid']])->unique())
            ->with('catalog:id,medicine_supplier_id')
            ->get()
            ->keyBy('uuid');

        $pairs = collect($data['pairs'])
            ->filter(fn (array $pair) => $items->has($pair['item_uuid']) && $items->has($pair['other_item_uuid']))
            ->map(fn (array $pair) => [$items[$pair['item_uuid']], $items[$pair['other_item_uuid']], $pair['reason'] ?? null])
            ->values()
            ->all();

        $count = $action->propose($pairs, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => $count === 0 ? 'Aucune nouvelle proposition.' : "{$count} rapprochement(s) proposé(s), à confirmer.",
            'data' => ['proposed' => $count],
        ]);
    }

    public function forget(Request $request, string $equivalenceUuid, DecideSupplierProductEquivalenceAction $action): JsonResponse
    {
        $equivalence = SupplierProductEquivalence::query()->where('uuid', $equivalenceUuid)->firstOrFail();
        $action->forget($equivalence, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Décision oubliée : la paire redevient ce que la règle en dit.']);
    }

    private function item(string $uuid): SupplierCatalogItem
    {
        return SupplierCatalogItem::query()->where('uuid', $uuid)->with('catalog:id,medicine_supplier_id')->firstOrFail();
    }

    private function authorizeRead(Request $request): void
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot('medicine_supplier_offers.view') && $actor->cannot('medicine_suppliers.view')) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
