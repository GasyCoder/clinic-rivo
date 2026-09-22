<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\ReceiveGoodsAction;
use App\Actions\Pharmacy\RecordSupplierInvoiceAction;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StoreGoodsReceiptRequest;
use App\Http\Requests\Pharmacy\StoreReceiptInvoiceRequest;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\MedicineLot;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\PurchasesOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('goods_receipts.view'), 403);

        $receipts = GoodsReceipt::query()
            ->with(['purchaseOrder.supplier:id,uuid,name', 'receivedBy:id,name'])
            ->withCount([
                'lines',
                'lines as awaiting_stock_count' => fn ($query) => $query->whereNull('stocked_at'),
                'invoices',
            ])
            ->latest('received_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GoodsReceipt $receipt) => [
                'uuid' => $receipt->uuid,
                'receipt_number' => $receipt->receipt_number,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'received_by' => $receipt->receivedBy?->name,
                'supplier' => $receipt->purchaseOrder->supplier->name,
                'supplier_uuid' => $receipt->purchaseOrder->supplier->uuid,
                'order_uuid' => $receipt->purchaseOrder->uuid,
                'order_number' => $receipt->purchaseOrder->order_number,
                'lines_count' => $receipt->lines_count,
                'awaiting_stock_count' => $receipt->awaiting_stock_count,
                // ADR-171 — sans facture, la réception l'attend.
                'invoice_pending' => $receipt->invoices_count === 0,
            ]);

        return Inertia::render('Pharmacy/GoodsReceipts/Index', [
            'receipts' => $receipts,
            'purchases' => app(PurchasesOverview::class)->for($request->user()),
            'can' => [
                'record_invoice' => $request->user()->can('supplier_invoices.create'),
                'stock' => $request->user()->can('stock.entry'),
            ],
        ]);
    }

    /**
     * ADR-171 — un assistant en deux étapes : ce qui est arrivé, puis la
     * facture du fournisseur. La seconde peut attendre.
     */
    public function create(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $user = $request->user();
        abort_unless($user?->can('goods_receipts.create'), 403);

        if (! in_array($purchaseOrder->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
            throw ValidationException::withMessages([
                'status' => 'Seule une commande envoyée au fournisseur peut être réceptionnée.',
            ]);
        }

        $purchaseOrder->load(['supplier:id,uuid,name,contact_name,phone', 'lines.medicine.catalogItem:id,code,name,unit']);
        $stocked = MedicineLot::query()
            ->whereIn('medicine_id', $purchaseOrder->lines->pluck('medicine_id'))
            ->pluck('medicine_id')
            ->flip();
        $received = GoodsReceiptLine::query()
            ->whereIn('medicine_id', $purchaseOrder->lines->pluck('medicine_id'))
            ->pluck('medicine_id')
            ->flip();
        $seeCost = $user->can('stock.cost.view');

        return Inertia::render('Pharmacy/GoodsReceipts/Create', [
            'order' => [
                'uuid' => $purchaseOrder->uuid,
                'order_number' => $purchaseOrder->order_number,
                'ordered_at' => $purchaseOrder->ordered_at?->toIso8601String(),
                'supplier' => $purchaseOrder->supplier->name,
                'supplier_contact' => collect([$purchaseOrder->supplier->contact_name, $purchaseOrder->supplier->phone])->filter()->implode(' · '),
                'lines' => $purchaseOrder->lines
                    ->filter(fn ($line) => $line->quantityRemaining() > 0)
                    ->map(fn ($line) => [
                        'id' => $line->id,
                        'medicine_name' => $line->medicine->catalogItem?->name,
                        'medicine_code' => $line->medicine->catalogItem?->code,
                        'unit' => $line->medicine->catalogItem?->unit,
                        'quantity_ordered' => $line->quantity_ordered,
                        'quantity_remaining' => $line->quantityRemaining(),
                        // Jamais reçu ni rangé : un produit nouveau pour la clinique.
                        'is_new' => ! $stocked->has($line->medicine_id) && ! $received->has($line->medicine_id),
                        // ADR-170 — le prix d'achat est confidentiel.
                        'unit_price' => $seeCost ? $line->unit_price : null,
                    ])->values(),
            ],
            'can' => [
                'record_cost' => $user->can('stock.cost.record'),
                'see_cost' => $seeCost,
                'rename' => $user->can('medicines.name.update'),
                'record_invoice' => $user->can('supplier_invoices.create'),
            ],
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request, PurchaseOrder $purchaseOrder, ReceiveGoodsAction $action): RedirectResponse
    {
        $invoice = $request->validated('invoice');

        if ($invoice !== null) {
            $invoice['attachment'] = $request->file('invoice.attachment');
        }

        $receipt = $action->execute(
            $purchaseOrder,
            $request->validated('lines'),
            $request->validated('notes'),
            $request->user(),
            $invoice,
        );

        return to_route('pharmacy.receipts.show', $receipt)->with('status', $invoice !== null
            ? 'Réception et facture enregistrées. Les produits attendent maintenant l’entrée en stock.'
            : 'Réception enregistrée. La facture reste en attente ; les produits attendent l’entrée en stock.');
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        $user = $request->user();
        abort_unless($user?->can('goods_receipts.view'), 403);

        $goodsReceipt->load([
            'purchaseOrder.supplier:id,uuid,name',
            'lines.medicine.catalogItem:id,code,name,unit',
            'lines.stocker:id,name',
            'receivedBy:id,name',
            'invoices:id,uuid,goods_receipt_id,invoice_number,invoice_date,due_date,total_amount',
        ]);
        $seeCost = $user->can('stock.cost.view');

        return Inertia::render('Pharmacy/GoodsReceipts/Show', [
            'receipt' => [
                'uuid' => $goodsReceipt->uuid,
                'receipt_number' => $goodsReceipt->receipt_number,
                'received_at' => $goodsReceipt->received_at?->toIso8601String(),
                'received_by' => $goodsReceipt->receivedBy?->name,
                'notes' => $goodsReceipt->notes,
                'order_uuid' => $goodsReceipt->purchaseOrder->uuid,
                'order_number' => $goodsReceipt->purchaseOrder->order_number,
                'supplier' => $goodsReceipt->purchaseOrder->supplier->name,
                'supplier_uuid' => $goodsReceipt->purchaseOrder->supplier->uuid,
                'lines' => $goodsReceipt->lines->map(fn (GoodsReceiptLine $line) => [
                    'uuid' => $line->uuid,
                    'medicine_uuid' => $line->medicine->uuid,
                    'medicine_name' => $line->medicine->catalogItem?->name,
                    'unit' => $line->medicine->catalogItem?->unit,
                    'lot_number' => $line->lot_number,
                    'expires_at' => $line->expires_at?->toDateString(),
                    'quantity_received' => $line->quantity_received,
                    'notes' => $line->notes,
                    'stocked_at' => $line->stocked_at?->toIso8601String(),
                    'stocked_by' => $line->stocker?->name,
                    'unit_purchase_price' => $seeCost ? $line->unit_purchase_price : null,
                ])->values(),
                'invoices' => $goodsReceipt->invoices->map(fn ($invoice) => [
                    'uuid' => $invoice->uuid,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'total_amount' => $invoice->total_amount,
                ])->values(),
            ],
            'canViewCost' => $seeCost,
            'can' => [
                'record_invoice' => $user->can('supplier_invoices.create'),
                'view_invoices' => $user->can('view-supplier-invoices'),
                'stock' => $user->can('stock.entry'),
            ],
        ]);
    }

    /** ADR-171 — la facture d'une réception enregistrée sans elle. */
    public function createInvoice(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        abort_unless($request->user()?->can('supplier_invoices.create'), 403);

        $goodsReceipt->load(['purchaseOrder.supplier:id,uuid,name', 'lines']);

        return Inertia::render('Pharmacy/GoodsReceipts/Invoice', [
            'receipt' => [
                'uuid' => $goodsReceipt->uuid,
                'receipt_number' => $goodsReceipt->receipt_number,
                'received_at' => $goodsReceipt->received_at?->toIso8601String(),
                'order_number' => $goodsReceipt->purchaseOrder->order_number,
                'supplier' => $goodsReceipt->purchaseOrder->supplier->name,
                'lines_count' => $goodsReceipt->lines->count(),
                'units' => $goodsReceipt->lines->sum('quantity_received'),
                'proposed_total' => $this->proposedTotal($goodsReceipt, $request->user()),
            ],
        ]);
    }

    public function storeInvoice(StoreReceiptInvoiceRequest $request, GoodsReceipt $goodsReceipt, RecordSupplierInvoiceAction $action): RedirectResponse
    {
        $goodsReceipt->load('purchaseOrder.supplier');
        $invoice = $action->execute($goodsReceipt->purchaseOrder->supplier, [
            ...$request->validated(),
            'attachment' => $request->file('attachment'),
            'purchase_order_uuid' => $goodsReceipt->purchaseOrder->uuid,
            'goods_receipt_uuid' => $goodsReceipt->uuid,
        ], CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.receipts.show', $goodsReceipt)->with('status', "Facture {$invoice->invoice_number} enregistrée.");
    }

    /**
     * Le montant que la réception laisse attendre, proposé à la saisie de la
     * facture — jamais imposé : c'est le papier du fournisseur qui fait foi.
     * Il révèle le coût d'achat : seulement avec `stock.cost.view` (ADR-170).
     */
    private function proposedTotal(GoodsReceipt $receipt, User $user): ?string
    {
        if (! $user->can('stock.cost.view')) {
            return null;
        }

        return number_format((float) $receipt->lines->sum(fn (GoodsReceiptLine $line) => $line->quantity_received * (float) $line->unit_purchase_price), 2, '.', '');
    }
}
