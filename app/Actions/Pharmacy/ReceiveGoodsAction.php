<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\Medicine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Services\Pharmacy\MedicineSaleDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-097, amendée par l'ADR-175 — réceptionner une commande constate ce qui
 * est arrivé : quantités, lots, péremptions, remarques. Rien n'entre encore
 * au stock : c'est l'entrée en stock (RecordReceivedStockAction) qui crée le
 * lot et le mouvement, une fois la marchandise contrôlée et rangée.
 *
 * Une réception peut être partielle (100 commandés, 80 arrivés) et porter,
 * dans le même geste, la facture du fournisseur ; sans facture, elle reste
 * « facture en attente » et la facture se saisit plus tard.
 */
class ReceiveGoodsAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly RecordSupplierInvoiceAction $recordInvoice,
    ) {}

    /**
     * @param array<int, array{
     *   purchase_order_line_id?: ?int,
     *   medicine_uuid?: ?string,
     *   supplier_catalog_item_uuid?: ?string,
     *   quantity_received: int,
     *   lot_number: string,
     *   expires_at: string,
     *   unit_purchase_price?: ?string,
     *   notes?: ?string,
     *   sale_name?: ?string
     * }> $lines
     * @param array{
     *   invoice_number: string,
     *   invoice_date: string,
     *   due_date?: ?string,
     *   total_amount: string,
     *   notes?: ?string,
     *   attachment?: ?UploadedFile
     * }|null $invoice
     */
    public function execute(PurchaseOrder $order, array $lines, ?string $notes, User $actor, ?array $invoice = null): GoodsReceipt
    {
        if ($actor->cannot('goods_receipts.create')) {
            throw new AuthorizationException('Vous ne pouvez pas réceptionner cette commande.');
        }

        foreach ($lines as $line) {
            if (filled($line['unit_purchase_price'] ?? null) && $actor->cannot('stock.cost.record')) {
                throw new AuthorizationException('Vous ne pouvez pas enregistrer un prix d’achat.');
            }

            if (filled($line['sale_name'] ?? null) && $actor->cannot('medicines.name.update')) {
                throw new AuthorizationException('Vous ne pouvez pas renommer un médicament.');
            }
        }

        if ($invoice !== null && $actor->cannot('supplier_invoices.create')) {
            throw new AuthorizationException('Vous ne pouvez pas enregistrer la facture du fournisseur.');
        }

        return DB::transaction(function () use ($order, $lines, $notes, $actor, $invoice): GoodsReceipt {
            $order = PurchaseOrder::query()->with('supplier')->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une commande envoyée au fournisseur peut être réceptionnée.',
                ]);
            }

            $receipt = GoodsReceipt::query()->create([
                'receipt_number' => $this->numbers->goodsReceipt(),
                'purchase_order_id' => $order->getKey(),
                'received_at' => now(),
                'notes' => $notes,
                'received_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            $sale = app(MedicineSaleDetails::class);

            foreach (array_values($lines) as $index => $lineData) {
                $medicine = $this->receiveLine($order, $receipt, $lineData, $index, $actor);
                $sale->collect($medicine->uuid, null, $lineData['sale_name'] ?? null, 'lines', $index);
            }

            $sale->apply("Réception {$receipt->receipt_number}", $actor);
            $order->refreshReceptionStatus();

            if ($invoice !== null) {
                $this->recordInvoice->execute($order->supplier, [
                    ...$invoice,
                    'purchase_order_uuid' => $order->uuid,
                    'goods_receipt_uuid' => $receipt->uuid,
                ], CatalogActor::fromUser($actor));
            }

            return $receipt->fresh(['lines', 'invoices']);
        });
    }

    /**
     * Une ligne réceptionnée solde une ligne de commande, ou constate un
     * article arrivé sans avoir été commandé (ADR-179).
     *
     * @param  array<string, mixed>  $lineData
     */
    private function receiveLine(PurchaseOrder $order, GoodsReceipt $receipt, array $lineData, int $index, User $actor): Medicine
    {
        return filled($lineData['purchase_order_line_id'] ?? null)
            ? $this->receiveOrderedLine($order, $receipt, $lineData, $index)
            : $this->receiveUnorderedLine($order, $receipt, $lineData, $index, $actor);
    }

    /** @param array<string, mixed> $lineData */
    private function receiveOrderedLine(PurchaseOrder $order, GoodsReceipt $receipt, array $lineData, int $index): Medicine
    {
        $orderLine = PurchaseOrderLine::query()
            ->with('medicine.catalogItem:id,name')
            ->where('purchase_order_id', $order->getKey())
            ->where('id', $lineData['purchase_order_line_id'])
            ->lockForUpdate()
            ->firstOrFail();

        $quantity = (int) $lineData['quantity_received'];
        $name = $orderLine->medicine->catalogItem?->name ?? 'Produit';

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                "lines.{$index}.quantity_received" => "{$name} : la quantité reçue doit être supérieure à zéro.",
            ]);
        }

        // ADR-179 — une ligne abandonnée n'attend plus rien. La recevoir
        // rouvrirait en silence un reliquat que quelqu'un a explicitement
        // déclaré perdu ; il faut d'abord revenir sur la rupture.
        if ($orderLine->isShort()) {
            throw ValidationException::withMessages([
                "lines.{$index}.quantity_received" => "{$name} est signalé en rupture sur cette commande. Revenez d’abord sur la rupture pour pouvoir le réceptionner.",
            ]);
        }

        if ($orderLine->quantity_received + $quantity > $orderLine->quantity_ordered) {
            throw ValidationException::withMessages([
                "lines.{$index}.quantity_received" => sprintf(
                    '%s : %d reçu(s), mais il n’en reste que %d à recevoir sur cette commande.',
                    $name, $quantity, $orderLine->quantityRemaining(),
                ),
            ]);
        }

        // ADR-174 — le prix d'achat n'est jamais redemandé : c'est celui de
        // la commande. Un compte autorisé peut seulement le corriger si le
        // fournisseur a facturé autre chose.
        $unitPurchasePrice = filled($lineData['unit_purchase_price'] ?? null)
            ? $lineData['unit_purchase_price']
            : $orderLine->unit_price;

        $this->createReceiptLine($receipt, $lineData, $orderLine->medicine_id, $unitPurchasePrice, $orderLine->getKey());

        $orderLine->update(['quantity_received' => $orderLine->quantity_received + $quantity]);

        return $orderLine->medicine;
    }

    /**
     * ADR-179 — un article livré qui n'était pas commandé.
     *
     * La commande **n'est pas réécrite** : l'ADR-098 pose qu'une commande
     * envoyée ne se modifie plus, et lui ajouter une ligne après coup
     * changerait l'engagement pris auprès du fournisseur. La réception
     * constate donc ce qui est arrivé, et cette ligne ne pointe aucune ligne
     * de commande. L'écart qui en résulte avec le montant commandé est
     * affiché à la saisie de la facture, jamais corrigé en silence.
     *
     * @param  array<string, mixed>  $lineData
     */
    private function receiveUnorderedLine(PurchaseOrder $order, GoodsReceipt $receipt, array $lineData, int $index, User $actor): Medicine
    {
        $medicine = $this->resolveUnorderedMedicine($order, $lineData, $index, $actor);

        if ((int) $lineData['quantity_received'] < 1) {
            $name = $medicine->catalogItem?->name ?? 'Produit';

            throw ValidationException::withMessages([
                "lines.{$index}.quantity_received" => "{$name} : la quantité reçue doit être supérieure à zéro.",
            ]);
        }

        // Aucune ligne de commande ne fige de prix ici : celui que le
        // fournisseur cote aujourd'hui, sinon celui que le pharmacien lit sur
        // le bon de livraison.
        $unitPurchasePrice = filled($lineData['unit_purchase_price'] ?? null)
            ? $lineData['unit_purchase_price']
            : $order->supplier->offers()
                ->where('medicine_id', $medicine->getKey())
                ->where('active_key', 'CURRENT')
                ->value('quoted_price');

        $this->createReceiptLine($receipt, $lineData, $medicine->getKey(), $unitPurchasePrice, null);

        return $medicine;
    }

    /** @param array<string, mixed> $lineData */
    private function resolveUnorderedMedicine(PurchaseOrder $order, array $lineData, int $index, User $actor): Medicine
    {
        if (filled($lineData['medicine_uuid'] ?? null)) {
            $medicine = Medicine::query()->with('catalogItem:id,name')
                ->where('uuid', $lineData['medicine_uuid'])
                ->firstOrFail();

            // ADR-182 — le livreur apporte ce que son fournisseur vend. Un
            // produit que la pharmacie tient mais que ce fournisseur n'a
            // jamais proposé ne se constate pas sur sa livraison : c'est
            // presque toujours le mauvais produit choisi dans la liste.
            if (! $order->supplier->suppliedMedicineIds()->contains($medicine->getKey())) {
                $name = $medicine->catalogItem?->name ?? 'Ce produit';

                throw ValidationException::withMessages([
                    "lines.{$index}.medicine_uuid" => "{$name} n’est pas au catalogue de {$order->supplier->name} : choisissez un produit que ce fournisseur vend.",
                ]);
            }

            return $medicine;
        }

        $item = SupplierCatalogItem::query()
            ->where('uuid', $lineData['supplier_catalog_item_uuid'])
            ->firstOrFail();

        // Comme à la commande (ADR-098) : l'UUID d'une ligne de catalogue est
        // public, et l'accepter d'un autre fournisseur rattacherait un produit
        // à un dossier qui ne l'a jamais proposé.
        if ($item->catalog()->value('medicine_supplier_id') !== $order->medicine_supplier_id) {
            throw ValidationException::withMessages([
                "lines.{$index}.supplier_catalog_item_uuid" => 'Cette ligne de catalogue appartient à un autre fournisseur.',
            ]);
        }

        // ADR-182 — une ligne déjà rattachée désigne un produit que la
        // clinique tient : la choisir ne crée rien, et n'exige donc pas le
        // droit de créer un médicament.
        $linked = $item->linked_medicine_id
            ? Medicine::query()->with('catalogItem:id,name')->where('active', true)->find($item->linked_medicine_id)
            : null;

        if ($linked) {
            return $linked;
        }

        // ADR-182 — réceptionner suffit à faire entrer au catalogue de la
        // clinique le produit que le fournisseur a livré.
        return app(CreateMedicineFromSupplierCatalogAction::class)
            ->execute($item, CatalogActor::fromUser($actor)->receivingDelivery())
            ->load('catalogItem:id,name');
    }

    /** @param array<string, mixed> $lineData */
    private function createReceiptLine(
        GoodsReceipt $receipt,
        array $lineData,
        int $medicineId,
        mixed $unitPurchasePrice,
        ?int $orderLineId,
    ): void {
        $receipt->lines()->create([
            'purchase_order_line_id' => $orderLineId,
            'medicine_id' => $medicineId,
            'lot_number' => trim((string) $lineData['lot_number']),
            'expires_at' => $lineData['expires_at'],
            'quantity_received' => (int) $lineData['quantity_received'],
            'unit_purchase_price' => $unitPurchasePrice,
            'notes' => filled($lineData['notes'] ?? null) ? trim((string) $lineData['notes']) : null,
        ]);
    }
}
