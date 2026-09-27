<?php

namespace App\Services\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;

/**
 * ADR-098 — the tabs of « Achats » (orders, goods awaiting reception,
 * receptions, supplier invoices) shared by the three list pages, so each
 * tab shows the same counts and only appears with its own permission.
 */
class PurchasesOverview
{
    public const TO_RECEIVE = 'TO_RECEIVE';

    /** @return array{can: array<string, bool>, counts: array<string, ?int>} */
    public function for(User $user): array
    {
        $orders = $user->can('purchase_orders.view');
        $receipts = $user->can('goods_receipts.view');
        $invoices = $user->can('supplier_invoices.view');

        return [
            'can' => [
                'orders' => $orders,
                'receipts' => $receipts,
                'invoices' => $invoices,
                'create_order' => $user->can('purchase_orders.create'),
                'create_invoice' => $user->can('supplier_invoices.create'),
                'receive' => $user->can('goods_receipts.create'),
            ],
            'counts' => [
                'orders' => $orders ? PurchaseOrder::query()->count() : null,
                'to_receive' => $orders ? PurchaseOrder::query()->whereIn('status', self::awaitingGoods())->count() : null,
                'receipts' => $receipts ? GoodsReceipt::query()->count() : null,
                'invoices' => $invoices ? SupplierInvoice::query()->count() : null,
            ],
        ];
    }

    public function firstTab(User $user): string
    {
        return match (true) {
            $user->can('purchase_orders.view') => '/pharmacy/purchase-orders',
            $user->can('goods_receipts.view') => '/pharmacy/receipts',
            default => '/pharmacy/supplier-invoices',
        };
    }

    /** @return array<int, string> */
    public static function awaitingGoods(): array
    {
        return [PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::PartiallyReceived->value];
    }
}
