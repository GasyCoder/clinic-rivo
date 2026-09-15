<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\ReceiveGoodsAction;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StoreGoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
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
            ->withCount('lines')
            ->latest('received_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GoodsReceipt $receipt) => [
                'uuid' => $receipt->uuid,
                'receipt_number' => $receipt->receipt_number,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'received_by' => $receipt->receivedBy?->name,
                'supplier' => $receipt->purchaseOrder->supplier->name,
                'order_uuid' => $receipt->purchaseOrder->uuid,
                'order_number' => $receipt->purchaseOrder->order_number,
                'lines_count' => $receipt->lines_count,
            ]);

        return Inertia::render('Pharmacy/GoodsReceipts/Index', [
            'receipts' => $receipts,
            'purchases' => app(PurchasesOverview::class)->for($request->user()),
        ]);
    }

    public function create(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        abort_unless($request->user()?->can('goods_receipts.create'), 403);

        if (! in_array($purchaseOrder->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
            throw ValidationException::withMessages([
                'status' => 'Seule une commande passée peut être réceptionnée.',
            ]);
        }

        $purchaseOrder->load(['supplier:id,uuid,name', 'lines.medicine.catalogItem:id,code,name']);

        return Inertia::render('Pharmacy/GoodsReceipts/Create', [
            'order' => [
                'uuid' => $purchaseOrder->uuid,
                'order_number' => $purchaseOrder->order_number,
                'supplier' => $purchaseOrder->supplier->name,
                'lines' => $purchaseOrder->lines
                    ->filter(fn ($line) => $line->quantityRemaining() > 0)
                    ->map(fn ($line) => [
                        'id' => $line->id,
                        'medicine_name' => $line->medicine->catalogItem?->name,
                        'medicine_code' => $line->medicine->catalogItem?->code,
                        'quantity_remaining' => $line->quantityRemaining(),
                        'unit_price' => $line->unit_price,
                    ])->values(),
            ],
            'can' => ['record_cost' => $request->user()->can('stock.cost.record')],
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request, PurchaseOrder $purchaseOrder, ReceiveGoodsAction $action): RedirectResponse
    {
        $action->execute($purchaseOrder, $request->validated('lines'), $request->validated('notes'), $request->user());

        return to_route('pharmacy.purchase-orders.show', $purchaseOrder)->with('status', 'Réception enregistrée.');
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        abort_unless($request->user()?->can('goods_receipts.view'), 403);

        $goodsReceipt->load([
            'purchaseOrder.supplier:id,uuid,name',
            'lines.medicine.catalogItem:id,code,name',
            'receivedBy:id,name',
        ]);

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
                'lines' => $goodsReceipt->lines->map(fn ($line) => [
                    'medicine_uuid' => $line->medicine->uuid,
                    'medicine_name' => $line->medicine->catalogItem?->name,
                    'lot_number' => $line->lot_number,
                    'expires_at' => $line->expires_at?->toDateString(),
                    'quantity_received' => $line->quantity_received,
                    'unit_purchase_price' => $line->unit_purchase_price,
                ])->values(),
            ],
        ]);
    }
}
