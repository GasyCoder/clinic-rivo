<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Pharmacy\ArchiveSupplierInvoiceAction;
use App\Actions\Pharmacy\CancelPurchaseOrderAction;
use App\Actions\Pharmacy\ClosePurchaseOrderAction;
use App\Actions\Pharmacy\ConfirmPurchaseOrderAction;
use App\Actions\Pharmacy\CreatePurchaseOrderAction;
use App\Actions\Pharmacy\RecordSupplierInvoiceAction;
use App\Actions\Pharmacy\RestoreSupplierInvoiceAction;
use App\Actions\Pharmacy\SubmitPurchaseOrderAction;
use App\Actions\Pharmacy\TrashPurchaseOrderAction;
use App\Actions\Pharmacy\UpdatePurchaseOrderAction;
use App\Actions\Pharmacy\UpdateSupplierInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StorePurchaseOrderRequest;
use App\Http\Requests\Pharmacy\StoreSupplierInvoiceRequest;
use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\ProcurementFormOptions;
use App\Services\Pharmacy\SupplierPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-098 — orders and supplier invoices written from the central portal.
 * Every write goes through the Actions the clinic uses, with the remote
 * Super Admin as actor; the rules, numbering and audit are therefore the
 * clinic's own. Receiving goods is deliberately absent: it stays a physical
 * act of the site's pharmacy.
 */
class PharmacyProcurementController extends Controller
{
    public function __construct(
        private readonly SupplierPresenter $presenter,
        private readonly ProcurementFormOptions $options,
    ) {}

    /** What an order form needs: the medicines, this supplier's current price first. */
    public function orderForm(Request $request, string $supplierUuid): JsonResponse
    {
        $this->authorizeActor($request, 'purchase_orders.create');
        $supplier = $this->supplier($supplierUuid);

        return response()->json(['data' => [
            'supplier' => $this->presenter->identity($supplier),
            'medicines' => $this->options->orderMedicines($supplier),
        ]]);
    }

    public function storeOrder(Request $request, string $supplierUuid, CreatePurchaseOrderAction $action, SubmitPurchaseOrderAction $submit): JsonResponse
    {
        $validated = $this->validateOrder($request);
        $actor = CatalogActor::fromRemoteRequest($request);
        $send = (bool) ($validated['send'] ?? false);
        $supplier = $this->supplier($supplierUuid);
        $order = DB::transaction(function () use ($action, $submit, $supplier, $validated, $actor, $send) {
            $order = $action->execute($supplier, $validated, $actor);

            return $send ? $submit->execute($order, $actor) : $order;
        });

        return response()->json([
            'message' => $send ? "Commande {$order->order_number} envoyée au fournisseur." : "Commande {$order->order_number} enregistrée en brouillon.",
            'data' => ['uuid' => $order->uuid, 'order_number' => $order->order_number],
        ], 201);
    }

    public function showOrder(Request $request, string $supplierUuid, string $orderUuid): JsonResponse
    {
        $this->authorizeActor($request, ['purchase_orders.view', 'medicine_suppliers.view']);
        $supplier = $this->supplier($supplierUuid, withArchived: true);
        $order = $this->order($supplier, $orderUuid)->load([
            'supplier:id,uuid,name,code,email',
            'creator:id,name',
            'lines.medicine.catalogItem:id,code,name,unit',
            'receipts.lines',
            'receipts.receivedBy:id,name',
            'invoices',
        ]);

        return response()->json(['data' => [
            'supplier' => $this->presenter->identity($supplier),
            'order' => $this->presenter->orderDetail($order),
        ]]);
    }

    /** Only a draft is corrected; UpdatePurchaseOrderAction refuses any other status. */
    public function updateOrder(Request $request, string $supplierUuid, string $orderUuid, UpdatePurchaseOrderAction $action, SubmitPurchaseOrderAction $submit): JsonResponse
    {
        $validated = $this->validateOrder($request);
        $actor = CatalogActor::fromRemoteRequest($request);
        $send = (bool) ($validated['send'] ?? false);
        $existing = $this->order($this->supplier($supplierUuid), $orderUuid);
        $order = DB::transaction(function () use ($action, $submit, $existing, $validated, $actor, $send) {
            $order = $action->execute($existing, $validated, $actor);

            return $send ? $submit->execute($order, $actor) : $order;
        });

        return response()->json(['message' => $send ? "Commande {$order->order_number} envoyée au fournisseur." : "Commande {$order->order_number} mise à jour.", 'data' => ['uuid' => $order->uuid]]);
    }

    public function submitOrder(Request $request, string $supplierUuid, string $orderUuid, SubmitPurchaseOrderAction $action): JsonResponse
    {
        $order = $action->execute($this->order($this->supplier($supplierUuid), $orderUuid), CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Commande {$order->order_number} passée."]);
    }

    public function cancelOrder(Request $request, string $supplierUuid, string $orderUuid, CancelPurchaseOrderAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $order = $action->execute(
            $this->order($this->supplier($supplierUuid, withArchived: true), $orderUuid),
            $validated['reason'],
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => "Commande {$order->order_number} annulée."]);
    }

    /**
     * ADR-179 — la confirmation que le fournisseur a envoyée. C'est le portail
     * qui passe les commandes (ADR-098) : c'est donc là qu'arrive le plus
     * souvent l'accusé du fournisseur, et le droit `purchase_orders.confirm`
     * y est accordé au Super Admin. La règle reste celle du site : l'Action
     * refuse une commande annulée, ici comme à la clinique.
     */
    public function confirmOrder(Request $request, string $supplierUuid, string $orderUuid, ConfirmPurchaseOrderAction $action): JsonResponse
    {
        $validated = $request->validate([
            'confirmed_at' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
        ]);

        $order = $action->execute(
            $this->order($this->supplier($supplierUuid), $orderUuid),
            [...$validated, 'attachment' => $request->file('attachment')],
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => "Confirmation du fournisseur enregistrée sur la commande {$order->order_number}."]);
    }

    public function unconfirmOrder(Request $request, string $supplierUuid, string $orderUuid, ConfirmPurchaseOrderAction $action): JsonResponse
    {
        $order = $action->revert(
            $this->order($this->supplier($supplierUuid, withArchived: true), $orderUuid),
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => "Confirmation retirée de la commande {$order->order_number}."]);
    }

    /**
     * Le document du fournisseur reste sur son site : le portail ne fait que
     * le relayer au navigateur, comme un fichier de catalogue (ADR-098). Sans
     * cela, un Super Admin déposerait une pièce qu'il ne pourrait plus ouvrir.
     */
    public function orderConfirmationDocument(Request $request, string $supplierUuid, string $orderUuid): StreamedResponse
    {
        $this->authorizeActor($request, ['purchase_orders.view', 'medicine_suppliers.view']);
        $order = $this->order($this->supplier($supplierUuid, withArchived: true), $orderUuid);

        abort_unless(filled($order->supplier_confirmation_attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($order->supplier_confirmation_attachment_path), 404);

        return Storage::disk('local')->download(
            $order->supplier_confirmation_attachment_path,
            $order->supplier_confirmation_attachment_original_name ?: 'confirmation',
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * ADR-179 — solder les reliquats d'une commande que le fournisseur
     * n'honorera plus. Renoncer à ce qui reste dû est une décision d'acheteur,
     * et l'acheteur est ici. Constater une rupture ligne à ligne reste au
     * site : c'est un constat de réception (ADR-176).
     */
    public function closeOrder(Request $request, string $supplierUuid, string $orderUuid, ClosePurchaseOrderAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $order = $action->execute(
            $this->order($this->supplier($supplierUuid), $orderUuid),
            $validated['reason'],
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => "Commande {$order->order_number} clôturée."]);
    }

    /**
     * ADR-176 — un brouillon ou une commande annulée part à la corbeille,
     * restaurable (ADR-061). Une commande vivante s'annule d'abord :
     * l'Action le refuse, ici comme à la clinique.
     */
    public function trashOrder(Request $request, string $supplierUuid, string $orderUuid, TrashPurchaseOrderAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $order = $action->execute(
            $this->order($this->supplier($supplierUuid, withArchived: true), $orderUuid),
            $validated['reason'],
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => "Commande {$order->order_number} mise à la corbeille."]);
    }

    /** What an invoice form needs: medicines, and this supplier's orders with their lines and receptions. */
    public function invoiceForm(Request $request, string $supplierUuid): JsonResponse
    {
        $this->authorizeActor($request, 'supplier_invoices.create');
        $supplier = $this->supplier($supplierUuid);

        return response()->json(['data' => [
            'supplier' => $this->presenter->identity($supplier),
            ...$this->options->invoiceOptions($supplier),
        ]]);
    }

    public function storeInvoice(Request $request, string $supplierUuid, RecordSupplierInvoiceAction $action): JsonResponse
    {
        $validated = $this->validateInvoice($request);
        $invoice = $action->execute($this->supplier($supplierUuid), $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "Facture {$invoice->invoice_number} enregistrée.",
            'data' => ['uuid' => $invoice->uuid, 'invoice_number' => $invoice->invoice_number],
        ], 201);
    }

    public function showInvoice(Request $request, string $supplierUuid, string $invoiceUuid): JsonResponse
    {
        $this->authorizeActor($request, ['supplier_invoices.view', 'medicine_suppliers.view']);
        $supplier = $this->supplier($supplierUuid, withArchived: true);
        $invoice = $this->invoice($supplier, $invoiceUuid, withArchived: true)
            ->load(['supplier:id,uuid,name', 'lines.medicine.catalogItem:id,code,name,unit', 'purchaseOrder', 'goodsReceipt', 'creator:id,name']);

        return response()->json(['data' => [
            'supplier' => $this->presenter->identity($supplier),
            'invoice' => $this->presenter->invoiceDetail($invoice),
            // The same choices as a new invoice, so the edit form is complete.
            ...($invoice->trashed() ? [] : $this->options->invoiceOptions($supplier)),
        ]]);
    }

    /** Sent as POST: a corrected invoice may carry a new document in a multipart body. */
    public function updateInvoice(Request $request, string $supplierUuid, string $invoiceUuid, UpdateSupplierInvoiceAction $action): JsonResponse
    {
        $validated = $this->validateInvoice($request);
        $invoice = $action->execute(
            $this->invoice($this->supplier($supplierUuid, withArchived: true), $invoiceUuid),
            $validated,
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => "Facture {$invoice->invoice_number} mise à jour.", 'data' => ['uuid' => $invoice->uuid]]);
    }

    public function archiveInvoice(Request $request, string $supplierUuid, string $invoiceUuid, ArchiveSupplierInvoiceAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $invoice = $this->invoice($this->supplier($supplierUuid, withArchived: true), $invoiceUuid);
        $action->execute($invoice, $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Facture {$invoice->invoice_number} archivée."]);
    }

    public function restoreInvoice(Request $request, string $supplierUuid, string $invoiceUuid, RestoreSupplierInvoiceAction $action): JsonResponse
    {
        $invoice = $this->invoice($this->supplier($supplierUuid, withArchived: true), $invoiceUuid, withArchived: true);
        $action->execute($invoice, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Facture {$invoice->invoice_number} restaurée."]);
    }

    /** @return array<string, mixed> */
    private function validateOrder(Request $request): array
    {
        $validated = $request->validate((new StorePurchaseOrderRequest)->rules());
        $errors = new MessageBag;
        StorePurchaseOrderRequest::assertDistinctProducts($validated['lines'], $errors);

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function validateInvoice(Request $request): array
    {
        // A multipart body (invoice with its document) carries the lines as JSON.
        if (is_string($request->input('lines'))) {
            $request->merge(['lines' => json_decode($request->input('lines'), true) ?: []]);
        }

        $validated = $request->validate((new StoreSupplierInvoiceRequest)->rules());
        $validated['attachment'] = $request->file('attachment');

        return $validated;
    }

    /** New orders and invoices go to active suppliers only; history stays readable. */
    private function supplier(string $uuid, bool $withArchived = false): MedicineSupplier
    {
        return MedicineSupplier::query()
            ->when($withArchived, fn ($query) => $query->withTrashed())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function order(MedicineSupplier $supplier, string $uuid): PurchaseOrder
    {
        return $supplier->purchaseOrders()->where('uuid', $uuid)->firstOrFail();
    }

    private function invoice(MedicineSupplier $supplier, string $uuid, bool $withArchived = false): SupplierInvoice
    {
        return SupplierInvoice::query()
            ->when($withArchived, fn ($query) => $query->withTrashed())
            ->where('medicine_supplier_id', $supplier->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** @param string|array<int, string> $permissions any one of them is enough */
    private function authorizeActor(Request $request, string|array $permissions): void
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if (collect((array) $permissions)->every(fn (string $permission) => $actor->cannot($permission))) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
