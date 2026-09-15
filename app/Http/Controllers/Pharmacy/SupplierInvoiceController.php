<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\ArchiveSupplierInvoiceAction;
use App\Actions\Pharmacy\RecordSupplierInvoiceAction;
use App\Actions\Pharmacy\RestoreSupplierInvoiceAction;
use App\Actions\Pharmacy\UpdateSupplierInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\ArchiveSupplierInvoiceRequest;
use App\Http\Requests\Pharmacy\StoreSupplierInvoiceRequest;
use App\Http\Requests\Pharmacy\UpdateSupplierInvoiceRequest;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\SupplierInvoice;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\ProcurementFormOptions;
use App\Services\Pharmacy\PurchasesOverview;
use App\Services\Pharmacy\SupplierPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('supplier_invoices.view')
            || ($request->filled('supplier') && $request->user()?->can('medicine_suppliers.view')), 403);

        $search = trim((string) $request->query('q', ''));
        $supplier = filled($request->query('supplier'))
            ? MedicineSupplier::query()->where('uuid', $request->query('supplier'))->first(['id', 'uuid', 'name'])
            : null;

        $invoices = SupplierInvoice::query()
            ->with('supplier:id,uuid,name')
            ->when($supplier, fn ($query) => $query->where('medicine_supplier_id', $supplier->id))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($related) => $related->where('name', 'like', "%{$search}%"))))
            ->latest('invoice_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierInvoice $invoice) => app(SupplierPresenter::class)->invoice($invoice));

        return Inertia::render('Pharmacy/SupplierInvoices/Index', [
            'invoices' => $invoices,
            'filters' => ['q' => $search, 'supplier' => $supplier?->uuid, 'supplier_name' => $supplier?->name],
            'can' => [
                'create' => $request->user()->can('supplier_invoices.create'),
                'update' => $request->user()->can('supplier_invoices.update'),
            ],
            'purchases' => app(PurchasesOverview::class)->for($request->user()),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('supplier_invoices.create'), 403);

        return Inertia::render('Pharmacy/SupplierInvoices/Create', [
            'suppliers' => MedicineSupplier::query()->orderBy('name')->get(['uuid', 'code', 'name']),
            'medicines' => Medicine::query()->where('active', true)
                ->with('catalogItem:id,code,name')
                ->get()
                ->map(fn (Medicine $medicine) => [
                    'uuid' => $medicine->uuid,
                    'code' => $medicine->catalogItem?->code,
                    'name' => $medicine->catalogItem?->name,
                ])->values(),
        ]);
    }

    public function store(StoreSupplierInvoiceRequest $request, MedicineSupplier $supplier, RecordSupplierInvoiceAction $action): RedirectResponse
    {
        $data = $request->validated();
        $data['attachment'] = $request->file('attachment');

        $invoice = $action->execute($supplier, $data, CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.supplier-invoices.index')->with('status', "Facture {$invoice->invoice_number} enregistrée.");
    }

    public function show(Request $request, SupplierInvoice $supplierInvoice): Response
    {
        abort_unless($request->user()?->can('view-supplier-invoices'), 403);

        $supplierInvoice->load(['supplier:id,uuid,name', 'lines.medicine.catalogItem:id,code,name', 'purchaseOrder', 'goodsReceipt', 'creator:id,name']);

        return Inertia::render('Pharmacy/SupplierInvoices/Show', [
            'invoice' => app(SupplierPresenter::class)->invoiceDetail($supplierInvoice),
            'can' => [
                'update' => $request->user()->can('supplier_invoices.update'),
                'delete' => $request->user()->can('supplier_invoices.delete'),
                'restore' => $request->user()->can('supplier_invoices.restore'),
            ],
        ]);
    }

    public function edit(SupplierInvoice $supplierInvoice, ProcurementFormOptions $options): Response|RedirectResponse
    {
        if ($supplierInvoice->trashed()) {
            return to_route('pharmacy.supplier-invoices.show', $supplierInvoice)
                ->withErrors(['invoice' => 'Restaurez la facture avant de la modifier.']);
        }

        $supplierInvoice->load(['supplier' => fn ($query) => $query->withTrashed(), 'lines.medicine.catalogItem:id,code,name', 'purchaseOrder', 'goodsReceipt', 'creator:id,name']);

        return Inertia::render('Pharmacy/SupplierInvoices/Edit', [
            'invoice' => app(SupplierPresenter::class)->invoiceDetail($supplierInvoice),
            ...$options->invoiceOptions($supplierInvoice->supplier),
        ]);
    }

    public function update(UpdateSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice, UpdateSupplierInvoiceAction $action): RedirectResponse
    {
        $data = $request->validated();
        $data['attachment'] = $request->file('attachment');
        $invoice = $action->execute($supplierInvoice, $data, CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.supplier-invoices.show', $invoice)->with('status', "Facture {$invoice->invoice_number} mise à jour.");
    }

    public function attachment(Request $request, SupplierInvoice $supplierInvoice): StreamedResponse
    {
        abort_unless($request->user()?->can('view-supplier-invoices'), 403);
        abort_unless($supplierInvoice->hasAttachment(), 404);
        abort_unless(Storage::disk('local')->exists($supplierInvoice->attachment_path), 404);

        return Storage::disk('local')->response(
            $supplierInvoice->attachment_path,
            $this->safeInlineName($supplierInvoice->attachment_original_name ?? 'facture'),
            [
                'Content-Type' => $supplierInvoice->attachment_mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Referrer-Policy' => 'no-referrer',
            ],
            'inline',
        );
    }

    public function destroy(ArchiveSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice, ArchiveSupplierInvoiceAction $action): RedirectResponse
    {
        $action->execute($supplierInvoice, $request->validated('reason'), CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.supplier-invoices.index')->with('status', 'Facture archivée.');
    }

    public function restore(Request $request, SupplierInvoice $supplierInvoice, RestoreSupplierInvoiceAction $action): RedirectResponse
    {
        $action->execute($supplierInvoice, CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Facture restaurée.');
    }

    private function safeInlineName(string $originalName): string
    {
        $name = basename(str_replace('\\', '/', $originalName));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $name) ?: 'facture';

        return Str::limit($name, 255, '');
    }
}
