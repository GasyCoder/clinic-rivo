<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\CancelPurchaseOrderAction;
use App\Actions\Pharmacy\CreatePurchaseOrderAction;
use App\Actions\Pharmacy\SubmitPurchaseOrderAction;
use App\Actions\Pharmacy\TrashPurchaseOrderAction;
use App\Actions\Pharmacy\UpdatePurchaseOrderAction;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\CancelPurchaseOrderRequest;
use App\Http\Requests\Pharmacy\StorePurchaseOrderRequest;
use App\Http\Requests\Pharmacy\TrashPurchaseOrderRequest;
use App\Http\Requests\Pharmacy\UpdatePurchaseOrderRequest;
use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\ProcurementFormOptions;
use App\Services\Pharmacy\PurchasesOverview;
use App\Services\Pharmacy\SupplierPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    private const SORTS = ['recent', 'oldest', 'amount', 'number', 'supplier'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        // Without the purchases permission, only one supplier's folder is readable.
        abort_unless($user?->can('purchase_orders.view')
            || ($request->filled('supplier') && $user?->can('medicine_suppliers.view')), 403);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'supplier' => (string) $request->query('supplier', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'recent',
        ];
        $supplier = $filters['supplier'] !== ''
            ? MedicineSupplier::query()->where('uuid', $filters['supplier'])->first(['id', 'uuid', 'name'])
            : null;

        // Tout sauf le statut : les compteurs disent combien de commandes
        // chaque filtre de statut montrerait, avec la même recherche.
        $base = PurchaseOrder::query()
            ->when($supplier, fn ($query) => $query->where('medicine_supplier_id', $supplier->id))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('order_number', 'like', "%{$filters['q']}%")
                ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', "%{$filters['q']}%"))))
            ->when($filters['from'] !== '', fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($query) => $query->whereDate('created_at', '<=', $filters['to']));

        $byStatus = (clone $base)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts = [
            'all' => (int) $byStatus->sum(),
            PurchasesOverview::TO_RECEIVE => (int) collect(PurchasesOverview::awaitingGoods())->sum(fn (string $status) => $byStatus[$status] ?? 0),
            ...collect(PurchaseOrderStatus::cases())->mapWithKeys(fn (PurchaseOrderStatus $status) => [$status->value => (int) ($byStatus[$status->value] ?? 0)])->all(),
        ];

        $orders = (clone $base)
            ->with('supplier:id,uuid,name')
            ->withCount('lines')
            ->when($filters['status'] === PurchasesOverview::TO_RECEIVE, fn ($query) => $query->whereIn('status', PurchasesOverview::awaitingGoods()))
            ->when($filters['status'] !== '' && $filters['status'] !== PurchasesOverview::TO_RECEIVE, fn ($query) => $query->where('status', $filters['status']))
            ->tap(fn ($query) => match ($filters['sort']) {
                'oldest' => $query->oldest('created_at'),
                'amount' => $query->orderByDesc('total_amount'),
                'number' => $query->orderByDesc('order_number'),
                'supplier' => $query->orderBy(MedicineSupplier::query()->select('name')->whereColumn('medicine_suppliers.id', 'purchase_orders.medicine_supplier_id')),
                default => $query->latest('created_at'),
            })
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseOrder $order) => [...$this->summarize($order), 'lines_count' => $order->lines_count]);

        return Inertia::render('Pharmacy/PurchaseOrders/Index', [
            'orders' => $orders,
            'filters' => [...$filters, 'supplier_name' => $supplier?->name],
            'counts' => $counts,
            'statuses' => collect(PurchaseOrderStatus::cases())->map(fn (PurchaseOrderStatus $status) => ['value' => $status->value, 'label' => $status->label()])->values(),
            'suppliers' => $user->can('purchase_orders.view')
                ? MedicineSupplier::query()->orderBy('name')->get(['uuid', 'name'])
                : [],
            'can' => [
                'create' => $user->can('purchase_orders.create'),
                'update' => $user->can('purchase_orders.update'),
                'submit' => $user->can('purchase_orders.submit'),
                'receive' => $user->can('goods_receipts.create'),
                'delete' => $user->can('purchase_orders.delete'),
            ],
            'purchases' => app(PurchasesOverview::class)->for($user),
        ]);
    }

    /**
     * ADR-098 — the products offered depend on the supplier, so nothing is
     * listed until one is chosen: this screen used to offer the whole clinic
     * catalogue whatever the supplier, which is exactly what the portal's
     * form stopped doing. `ProcurementFormOptions` is the single answer to
     * "what can this supplier deliver", here as there.
     */
    public function create(Request $request, ProcurementFormOptions $options): Response
    {
        abort_unless($request->user()?->can('purchase_orders.create'), 403);

        $supplier = filled($request->query('supplier'))
            ? MedicineSupplier::query()->where('uuid', $request->query('supplier'))->first()
            : null;

        return Inertia::render('Pharmacy/PurchaseOrders/Create', [
            'suppliers' => MedicineSupplier::query()->orderBy('name')->get(['uuid', 'code', 'name', 'contact_name', 'phone', 'email']),
            'supplierUuid' => $supplier?->uuid ?? '',
            'medicines' => $supplier ? $options->orderMedicines($supplier) : [],
            'canSend' => $request->user()->can('purchase_orders.submit'),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request, MedicineSupplier $supplier, CreatePurchaseOrderAction $action, SubmitPurchaseOrderAction $submit): RedirectResponse
    {
        $actor = CatalogActor::fromUser($request->user());
        $send = $request->boolean('send');
        $order = DB::transaction(function () use ($action, $submit, $supplier, $request, $actor, $send) {
            $order = $action->execute($supplier, $request->validated(), $actor);

            return $send ? $submit->execute($order, $actor) : $order;
        });

        return to_route('pharmacy.purchase-orders.show', $order)->with('status', $send
            ? "Commande {$order->order_number} envoyée au fournisseur."
            : "Commande {$order->order_number} enregistrée en brouillon.");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        abort_unless($request->user()?->can('view-supplier-orders'), 403);

        $purchaseOrder->load([
            'supplier:id,uuid,name,code',
            'lines.medicine.catalogItem:id,code,name',
            'receipts.lines',
            'receipts.receivedBy:id,name',
            'invoices',
        ]);

        return Inertia::render('Pharmacy/PurchaseOrders/Show', [
            'order' => app(SupplierPresenter::class)->orderDetail($purchaseOrder),
            'can' => [
                'update' => $request->user()->can('purchase_orders.update'),
                'submit' => $request->user()->can('purchase_orders.submit'),
                'cancel' => $request->user()->can('purchase_orders.cancel'),
                'receive' => $request->user()->can('goods_receipts.create'),
            ],
        ]);
    }

    /** ADR-098 — only a draft is corrected; a sent order is cancelled, never rewritten. */
    public function edit(Request $request, PurchaseOrder $purchaseOrder, ProcurementFormOptions $options): Response|RedirectResponse
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            return to_route('pharmacy.purchase-orders.show', $purchaseOrder)
                ->withErrors(['status' => 'Seule une commande en brouillon peut être modifiée.']);
        }

        $purchaseOrder->load(['supplier' => fn ($query) => $query->withTrashed(), 'lines.medicine.catalogItem:id,code,name', 'receipts.lines', 'invoices']);

        return Inertia::render('Pharmacy/PurchaseOrders/Edit', [
            'order' => app(SupplierPresenter::class)->orderDetail($purchaseOrder),
            'supplier' => app(SupplierPresenter::class)->identity($purchaseOrder->supplier),
            'medicines' => $options->orderMedicines($purchaseOrder->supplier),
            'canSend' => $request->user()->can('purchase_orders.submit'),
        ]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, UpdatePurchaseOrderAction $action, SubmitPurchaseOrderAction $submit): RedirectResponse
    {
        $actor = CatalogActor::fromUser($request->user());
        $send = $request->boolean('send');
        DB::transaction(function () use ($action, $submit, $purchaseOrder, $request, $actor, $send): void {
            $order = $action->execute($purchaseOrder, $request->validated(), $actor);

            if ($send) {
                $submit->execute($order, $actor);
            }
        });

        return to_route('pharmacy.purchase-orders.show', $purchaseOrder)->with('status', $send ? 'Commande envoyée au fournisseur.' : 'Commande mise à jour.');
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, SubmitPurchaseOrderAction $action): RedirectResponse
    {
        $action->execute($purchaseOrder, CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Commande passée.');
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrderAction $action): RedirectResponse
    {
        $action->execute($purchaseOrder, $request->validated('reason'), CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Commande annulée.');
    }

    /** ADR-113 — un brouillon jamais envoyé part à la corbeille, avec son motif. */
    public function destroy(TrashPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, TrashPurchaseOrderAction $action): RedirectResponse
    {
        $action->execute($purchaseOrder, $request->validated('reason'), CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.purchase-orders.index')->with('status', "Brouillon {$purchaseOrder->order_number} mis à la corbeille. Il peut être restauré depuis la Corbeille.");
    }

    /** @return array<string, mixed> */
    private function summarize(PurchaseOrder $order): array
    {
        // Shared with the central portal's read of a supplier folder (ADR-098).
        return app(SupplierPresenter::class)->order($order);
    }
}
