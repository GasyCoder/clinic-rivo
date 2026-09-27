<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\Concerns\RespondsToSiteApi;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-098 — orders and supplier invoices of one site's supplier, written
 * from the central portal through that site's API. The site applies its own
 * rules and audits the Super Admin; the portal only forwards and shows back
 * the result. Receiving goods stays at the site.
 */
class PharmacyProcurementController extends Controller
{
    use RespondsToSiteApi;

    /**
     * Prepare an order across suppliers: compare every current price for the
     * same medicine, then fill a basket. A purchase order still belongs to
     * one supplier (ADR-097) — a basket spanning three suppliers therefore
     * creates three orders, one per supplier, never a single mixed one.
     */
    public function compare(Request $request, string $site, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $selected = array_values(array_filter((array) $request->query('suppliers', [])));
        $result = $client->pharmacySupplierOffers($site, $request->user(), $selected);

        return Inertia::render('SuperAdmin/PharmacySuppliers/Compare', [
            'targetSite' => $result['site'],
            'suppliers' => data_get($result, 'data.suppliers', []),
            'medicines' => data_get($result, 'data.medicines', []),
            // ADR-181 — combien de lignes de catalogue ressemblent à un
            // produit déjà tenu par la clinique, sous un autre nom.
            'toReconcile' => (int) data_get($result, 'data.to_reconcile', 0),
            'selectedSuppliers' => $selected,
            'error' => $result['ok'] ? null : $result['message'],
        ]);
    }

    /** One draft order per supplier, from the basket built on the comparison. */
    public function storeOrders(Request $request, string $site, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate([
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.supplier_uuid' => ['required', 'uuid'],
            'orders.*.lines' => ['required', 'array', 'min:1'],
            // A line names a clinic medicine, or a supplier catalogue line
            // the site turns into one when the order is placed (ADR-098).
            'orders.*.lines.*.medicine_uuid' => ['nullable', 'required_without:orders.*.lines.*.supplier_catalog_item_uuid', 'uuid'],
            'orders.*.lines.*.supplier_catalog_item_uuid' => ['nullable', 'required_without:orders.*.lines.*.medicine_uuid', 'uuid'],
            'orders.*.lines.*.quantity' => ['required', 'integer', 'min:1'],
            'orders.*.lines.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'expected_delivery_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $created = [];
        $failures = [];

        // Each supplier is a separate command on the site: one failing
        // supplier never cancels the orders already accepted, and the screen
        // says exactly which ones went through.
        foreach ($validated['orders'] as $order) {
            $result = $client->pharmacyProcurement($site, $order['supplier_uuid'], $request->user(), 'POST', 'orders', [
                'lines' => $order['lines'],
                ...array_filter([
                    'expected_delivery_at' => $validated['expected_delivery_at'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ], fn ($value) => filled($value)),
            ]);

            if ($result['ok']) {
                $created[] = ['supplier_uuid' => $order['supplier_uuid'], 'uuid' => data_get($result, 'data.uuid'), 'number' => data_get($result, 'data.order_number')];
            } else {
                $failures[] = $result['message'] ?: 'Commande refusée par le site.';
            }
        }

        if ($created === []) {
            return back()->withErrors(['orders' => implode(' ', $failures) ?: 'Aucune commande n’a pu être créée.'])->withInput();
        }

        $status = count($created) === 1
            ? 'Commande '.($created[0]['number'] ?? '').' créée en brouillon.'
            : count($created).' commandes créées en brouillon, une par fournisseur.';

        if (count($created) === 1) {
            return to_route('super-admin.pharmacy-suppliers.orders.show', [mb_strtoupper($site), $created[0]['supplier_uuid'], $created[0]['uuid']])
                ->with('status', $status);
        }

        return to_route('super-admin.pharmacy-suppliers.index', ['site' => mb_strtoupper($site)])
            ->with('status', $failures === [] ? $status : $status.' '.implode(' ', $failures));
    }

    public function createOrder(Request $request, string $site, string $supplier, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'GET', 'order-form');

        return Inertia::render('SuperAdmin/PharmacySuppliers/OrderCreate', [
            'targetSite' => $result['site'],
            'supplier' => data_get($result, 'data.supplier'),
            'medicines' => data_get($result, 'data.medicines', []),
            'canSend' => $request->user()->can('purchase_orders.submit'),
            'error' => $result['ok'] ? null : $result['message'],
        ]);
    }

    public function storeOrder(Request $request, string $site, string $supplier, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $request->validate(['lines' => ['required', 'array', 'min:1']]);
        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'POST', 'orders', $request->only(['expected_delivery_at', 'notes', 'lines', 'send']));

        if (! $result['ok']) {
            return back()->withErrors($this->siteErrors($result))->withInput();
        }

        return to_route('super-admin.pharmacy-suppliers.orders.show', [mb_strtoupper($site), $supplier, data_get($result, 'data.uuid')])
            ->with('status', $result['message'] ?: 'Commande créée en brouillon.');
    }

    public function showOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'GET', 'orders/'.rawurlencode($order));
        $user = $request->user();

        return Inertia::render('SuperAdmin/PharmacySuppliers/OrderShow', [
            'targetSite' => $result['site'],
            'supplier' => data_get($result, 'data.supplier'),
            'order' => data_get($result, 'data.order'),
            'error' => $result['ok'] ? null : $result['message'],
            'can' => [
                'update' => $user->can('purchase_orders.update'),
                'submit' => $user->can('purchase_orders.submit'),
                'cancel' => $user->can('purchase_orders.cancel'),
                'create_invoice' => $user->can('supplier_invoices.create') && ! data_get($result, 'data.supplier.archived', false),
                // ADR-179 — le portail passe les commandes : l'accusé du
                // fournisseur y arrive, et renoncer à un reliquat y est une
                // décision d'acheteur. Constater une rupture ligne à ligne
                // reste au site, avec la marchandise sous les yeux (ADR-176).
                'confirm' => $user->can('purchase_orders.confirm'),
                'close' => $user->can('purchase_orders.cancel'),
            ],
        ]);
    }

    /** A draft only: the site refuses any other status and the page says so. */
    public function editOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $user = $request->user();
        $detail = $client->pharmacyProcurement($site, $supplier, $user, 'GET', 'orders/'.rawurlencode($order));
        $form = $detail['ok'] ? $client->pharmacyProcurement($site, $supplier, $user, 'GET', 'order-form') : $detail;
        $status = data_get($detail, 'data.order.status');

        return Inertia::render('SuperAdmin/PharmacySuppliers/OrderEdit', [
            'targetSite' => $detail['site'],
            'supplier' => data_get($detail, 'data.supplier'),
            'order' => data_get($detail, 'data.order'),
            'medicines' => data_get($form, 'data.medicines', []),
            'canSend' => $user->can('purchase_orders.submit'),
            'error' => match (true) {
                ! $detail['ok'] => $detail['message'],
                ! $form['ok'] => $form['message'],
                $status !== 'DRAFT' => 'Seule une commande en brouillon peut être modifiée. Une commande envoyée s’annule, elle ne se réécrit pas.',
                default => null,
            },
        ]);
    }

    public function updateOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $request->validate(['lines' => ['required', 'array', 'min:1']]);
        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'PUT', 'orders/'.rawurlencode($order), $request->only(['expected_delivery_at', 'notes', 'lines', 'send']));

        if (! $result['ok']) {
            return back()->withErrors($this->siteErrors($result))->withInput();
        }

        return to_route('super-admin.pharmacy-suppliers.orders.show', [mb_strtoupper($site), $supplier, $order])
            ->with('status', $result['message'] ?: 'Commande mise à jour.');
    }

    public function editInvoice(Request $request, string $site, string $supplier, string $invoice, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'GET', 'invoices/'.rawurlencode($invoice));

        return Inertia::render('SuperAdmin/PharmacySuppliers/InvoiceEdit', [
            'targetSite' => $result['site'],
            'supplier' => data_get($result, 'data.supplier'),
            'invoice' => data_get($result, 'data.invoice'),
            'medicines' => data_get($result, 'data.medicines', []),
            'orders' => data_get($result, 'data.orders', []),
            'error' => match (true) {
                ! $result['ok'] => $result['message'],
                (bool) data_get($result, 'data.invoice.archived') => 'Restaurez la facture avant de la modifier.',
                default => null,
            },
        ]);
    }

    public function updateInvoice(Request $request, string $site, string $supplier, string $invoice, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $request->validate([
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
            'total_amount' => ['required_without:lines', 'nullable', 'numeric', 'gt:0'],
            'lines' => ['nullable', 'array'],
        ]);
        $attachment = $request->file('attachment');
        $payload = collect($request->only(['invoice_number', 'invoice_date', 'due_date', 'total_amount', 'purchase_order_uuid', 'goods_receipt_uuid', 'notes']))
            ->filter(fn ($value) => filled($value))
            ->all();
        $lines = array_values($request->input('lines') ?? []);

        // A multipart body cannot nest arrays: the lines travel as JSON with
        // the document. An invoice without lines sends none at all, so the
        // site reads its total instead of an empty detail.
        if ($lines !== []) {
            $payload['lines'] = $attachment ? json_encode($lines) : $lines;
        }

        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'POST', 'invoices/'.rawurlencode($invoice).'/update', $payload, $attachment);

        if (! $result['ok']) {
            return back()->withErrors($this->siteErrors($result));
        }

        return to_route('super-admin.pharmacy-suppliers.invoices.show', [mb_strtoupper($site), $supplier, $invoice])
            ->with('status', $result['message'] ?: 'Facture mise à jour.');
    }

    public function submitOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond(
            $client->pharmacyProcurement($site, $supplier, $request->user(), 'POST', 'orders/'.rawurlencode($order).'/submit'),
            'Commande passée.',
        );
    }

    public function cancelOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond(
            $client->pharmacyProcurement($site, $supplier, $request->user(), 'POST', 'orders/'.rawurlencode($order).'/cancel', $validated),
            'Commande annulée.',
        );
    }

    /**
     * ADR-179 — la confirmation que le fournisseur a envoyée : une trace, et
     * rien n'en dépend. Son document part en multipart et reste sur le site,
     * comme un fichier de catalogue (ADR-098).
     */
    public function confirmOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate([
            'confirmed_at' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
        ], [
            'confirmed_at.required' => 'Indiquez la date de la confirmation du fournisseur.',
            'confirmed_at.before_or_equal' => 'La confirmation ne peut pas être datée dans le futur.',
        ]);

        return $this->respond(
            $client->pharmacyProcurement(
                $site,
                $supplier,
                $request->user(),
                'POST',
                'orders/'.rawurlencode($order).'/confirmation',
                collect($validated)->except('attachment')->all(),
                $request->file('attachment'),
            ),
            'Confirmation du fournisseur enregistrée.',
        );
    }

    public function unconfirmOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond(
            $client->pharmacyProcurement($site, $supplier, $request->user(), 'DELETE', 'orders/'.rawurlencode($order).'/confirmation'),
            'Confirmation du fournisseur retirée.',
        );
    }

    /** Le document reste sur son site ; le portail ne fait que le relayer. */
    public function confirmationDocument(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): StreamedResponse
    {
        $this->assertSite($site);
        $name = (string) $request->query('name', 'confirmation');
        $file = $client->pharmacyOrderConfirmationFile($site, $supplier, $order, $request->user(), $name);

        abort_unless($file['ok'], 404, $file['message']);

        return response()->streamDownload(
            fn () => print ($file['body']),
            preg_replace('/[^\w .\-]/u', '_', $name) ?: 'confirmation',
            ['Content-Type' => $file['content_type'], 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * ADR-179 — solder les reliquats d'une commande que le fournisseur
     * n'honorera plus. Ce n'est pas une annulation : la commande a été
     * envoyée, souvent livrée en partie, et peut porter une facture.
     */
    public function closeOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [
            'reason.required' => 'Indiquez pourquoi cette commande est clôturée sans être complète.',
        ]);

        return $this->respond(
            $client->pharmacyProcurement($site, $supplier, $request->user(), 'POST', 'orders/'.rawurlencode($order).'/close', $validated),
            'Commande clôturée. Ses reliquats ne sont plus attendus.',
        );
    }

    /** ADR-176 — brouillon ou commande annulée ; le site rejuge la règle. */
    public function trashOrder(Request $request, string $site, string $supplier, string $order, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond(
            $client->pharmacyProcurement($site, $supplier, $request->user(), 'DELETE', 'orders/'.rawurlencode($order), $validated),
            'Commande mise à la corbeille.',
        );
    }

    public function createInvoice(Request $request, string $site, string $supplier, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'GET', 'invoice-form');

        return Inertia::render('SuperAdmin/PharmacySuppliers/InvoiceCreate', [
            'targetSite' => $result['site'],
            'supplier' => data_get($result, 'data.supplier'),
            'medicines' => data_get($result, 'data.medicines', []),
            'orders' => data_get($result, 'data.orders', []),
            'initialOrderUuid' => $request->query('order'),
            'error' => $result['ok'] ? null : $result['message'],
        ]);
    }

    public function storeInvoice(Request $request, string $site, string $supplier, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $request->validate([
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
            'total_amount' => ['required_without:lines', 'nullable', 'numeric', 'gt:0'],
            'lines' => ['nullable', 'array'],
        ]);
        $attachment = $request->file('attachment');
        $payload = collect($request->only(['invoice_number', 'invoice_date', 'due_date', 'total_amount', 'purchase_order_uuid', 'goods_receipt_uuid', 'notes']))
            ->filter(fn ($value) => filled($value))
            ->all();
        $lines = array_values($request->input('lines') ?? []);

        // A multipart body cannot nest arrays: the lines travel as JSON with
        // the document. An invoice without lines sends none at all, so the
        // site reads its total instead of an empty detail.
        if ($lines !== []) {
            $payload['lines'] = $attachment ? json_encode($lines) : $lines;
        }

        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'POST', 'invoices', $payload, $attachment);

        if (! $result['ok']) {
            return back()->withErrors($this->siteErrors($result));
        }

        return to_route('super-admin.pharmacy-suppliers.invoices.show', [mb_strtoupper($site), $supplier, data_get($result, 'data.uuid')])
            ->with('status', $result['message'] ?: 'Facture enregistrée.');
    }

    public function showInvoice(Request $request, string $site, string $supplier, string $invoice, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacyProcurement($site, $supplier, $request->user(), 'GET', 'invoices/'.rawurlencode($invoice));
        $user = $request->user();

        return Inertia::render('SuperAdmin/PharmacySuppliers/InvoiceShow', [
            'targetSite' => $result['site'],
            'supplier' => data_get($result, 'data.supplier'),
            'invoice' => data_get($result, 'data.invoice'),
            'error' => $result['ok'] ? null : $result['message'],
            'can' => [
                'update' => $user->can('supplier_invoices.update'),
                'delete' => $user->can('supplier_invoices.delete'),
                'restore' => $user->can('supplier_invoices.restore'),
            ],
        ]);
    }

    public function archiveInvoice(Request $request, string $site, string $supplier, string $invoice, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond(
            $client->pharmacyProcurement($site, $supplier, $request->user(), 'DELETE', 'invoices/'.rawurlencode($invoice), $validated),
            'Facture archivée.',
        );
    }

    public function restoreInvoice(Request $request, string $site, string $supplier, string $invoice, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond(
            $client->pharmacyProcurement($site, $supplier, $request->user(), 'POST', 'invoices/'.rawurlencode($invoice).'/restore'),
            'Facture restaurée.',
        );
    }
}
