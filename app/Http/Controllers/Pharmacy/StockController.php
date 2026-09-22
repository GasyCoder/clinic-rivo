<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\AdjustMedicineStockAction;
use App\Actions\Pharmacy\RecordInventoryCountAction;
use App\Actions\Pharmacy\RecordReceivedStockAction;
use App\Actions\Pharmacy\RecordStockEntriesAction;
use App\Actions\Pharmacy\RecordStockEntryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StoreInventoryCountRequest;
use App\Http\Requests\Pharmacy\StoreReceivedStockRequest;
use App\Http\Requests\Pharmacy\StoreStockAdjustmentRequest;
use App\Http\Requests\Pharmacy\StoreStockEntriesRequest;
use App\Http\Requests\Pharmacy\StoreStockEntryRequest;
use App\Models\Medicine;
use App\Models\PharmacyStockMovement;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Pharmacy\PharmacyWorkspaceService;
use App\Services\Pharmacy\ReceivedStockQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function index(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/Stock/Index', $workspace->stock($request->user()));
    }

    public function show(Request $request, Medicine $medicine, PharmacyWorkspaceService $workspace): Response
    {
        $data = $workspace->stockMedicine($request->user(), $medicine);

        // ADR-098 — the movements were recorded but never shown: the last ones,
        // read-only, for accounts allowed to see lots.
        $data['movements'] = $data['capabilities']['can_view_lots']
            ? PharmacyStockMovement::query()
                ->whereIn('medicine_lot_id', $medicine->lots()->pluck('id'))
                ->with('medicineLot:id,lot_number')
                ->latest('occurred_at')->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (PharmacyStockMovement $movement) => [
                    'id' => $movement->id,
                    'occurred_at' => $movement->occurred_at?->toIso8601String(),
                    'type_label' => $movement->type->label(),
                    'quantity_delta' => $movement->quantity_delta,
                    'balance_after' => $movement->balance_after,
                    'lot_number' => $movement->medicineLot?->lot_number,
                    'reason' => $movement->reason,
                    'performed_by' => User::query()->whereKey($movement->performed_by)->value('name'),
                ])
            : [];

        // ADR-098 — one standard clinic name, and the names each supplier uses for it.
        $data['supplierNames'] = $data['capabilities']['can_view_suppliers']
            ? SupplierCatalogItem::query()
                ->where('linked_medicine_id', $medicine->id)
                ->with(['catalog' => fn ($query) => $query->withTrashed()->with(['supplier' => fn ($inner) => $inner->withTrashed()])])
                ->latest('id')
                ->get()
                ->unique(fn (SupplierCatalogItem $item) => $item->catalog?->medicine_supplier_id)
                ->map(fn (SupplierCatalogItem $item) => [
                    'supplier' => $item->catalog?->supplier?->name,
                    'supplier_uuid' => $item->catalog?->supplier?->uuid,
                    'label' => $item->medicine_label,
                    'reference' => $item->reference,
                    'presentation' => $item->presentation,
                    // ADR-174 — le prix d'achat reste confidentiel.
                    'purchase_price' => ! $data['capabilities']['can_view_cost'] ? null : $medicine->supplierOffers()
                        ->where('medicine_supplier_id', $item->catalog?->medicine_supplier_id)
                        ->where('active_key', 'CURRENT')
                        ->value('quoted_price') ?? $item->supplier_price,
                ])
                ->values()
            : [];

        return Inertia::render('Pharmacy/Stock/Show', $data);
    }

    /**
     * ADR-175 — un seul écran d'entrée en stock. Ce qui a été réceptionné y
     * arrive déjà rempli (fournisseur, commande, lots, quantités) : il ne
     * reste qu'à relire et valider. La saisie sans commande (don, stock de
     * départ) utilise le même tableau. Aucune date n'est saisie : l'entrée
     * est datée par le serveur.
     */
    public function createEntry(Request $request, PharmacyWorkspaceService $workspace, ReceivedStockQueue $queue): Response
    {
        return Inertia::render('Pharmacy/Stock/Entries/Create', [
            ...$workspace->stockEntryForm($request->user()),
            'pending' => $queue->pending($request->user()),
            'initialMode' => $request->query('mode') === 'manuelle' ? 'manual' : 'received',
            'initialSupplier' => (string) $request->query('fournisseur', ''),
            'initialOrder' => (string) $request->query('commande', ''),
        ]);
    }

    /** ADR-175 — les lignes réceptionnées entrent au stock, tout ou rien. */
    public function storeReceived(StoreReceivedStockRequest $request, RecordReceivedStockAction $action): RedirectResponse
    {
        $count = $action->execute($request->validated('lines'), $request->user());

        return to_route('pharmacy.stock.entries.create')
            ->with('status', $count > 1 ? "{$count} produits sont entrés en stock." : 'Le produit est entré en stock.');
    }

    public function storeEntry(StoreStockEntryRequest $request, RecordStockEntryAction $action): RedirectResponse
    {
        // ADR-175 — la date d'entrée est celle du serveur, jamais saisie.
        $action->execute([...$request->validated(), 'received_at' => now()->toDateString()], $request->user());

        return to_route('pharmacy.stock.index')->with('status', 'L’entrée de stock a été enregistrée.');
    }

    public function storeEntries(StoreStockEntriesRequest $request, RecordStockEntriesAction $action): RedirectResponse
    {
        $validated = $request->validated();
        $count = $action->execute(
            // ADR-175 — la date d'entrée est celle du serveur, jamais saisie.
            // Une seule question à l'écran — d'où vient la marchandise — qui
            // sert de provenance et de motif ; le rangement est le stock du site.
            [
                'supplier_uuid' => $validated['supplier_uuid'] ?? null,
                'origin' => $validated['origin'],
                'destination' => $validated['destination'] ?? 'Stock pharmacie',
                'reason' => $validated['reason'] ?? $validated['origin'],
                'received_at' => now()->toDateString(),
            ],
            $validated['entries'],
            $request->user(),
        );

        return to_route('pharmacy.stock.entries.create', ['mode' => 'manuelle'])->with('status', "{$count} entrée(s) de stock enregistrée(s).");
    }

    public function inventory(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/Stock/Inventory', $workspace->inventorySheet($request->user()));
    }

    public function storeInventory(StoreInventoryCountRequest $request, RecordInventoryCountAction $action): RedirectResponse
    {
        $validated = $request->validated();
        $result = $action->execute($validated['counts'], $validated['reason'], $request->user());

        return to_route('pharmacy.stock.index')->with('status', sprintf(
            'Inventaire validé : %d lot(s) corrigé(s), %d conforme(s).',
            $result['adjusted'],
            $result['unchanged'],
        ));
    }

    public function createAdjustment(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/Stock/Adjustments/Create', $workspace->stockAdjustmentForm($request->user()));
    }

    public function storeAdjustment(StoreStockAdjustmentRequest $request, AdjustMedicineStockAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user());

        return to_route('pharmacy.stock.index')->with('status', 'La correction de stock a été enregistrée.');
    }
}
