<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\Concerns\RespondsToSiteApi;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-098 — a site's medicine records and families, corrected from the portal
 * through that site's API. The site applies its own rules and audits the Super
 * Admin; the portal forwards and shows back the answer.
 */
class PharmacyCatalogController extends Controller
{
    use RespondsToSiteApi;

    private const MEDICINE_FIELDS = [
        'name', 'generic_name', 'form', 'strength', 'unit', 'manufacturer', 'barcode', 'medicine_category_uuid',
        'supplier_uuids', 'minimum_stock', 'prescription_required', 'sale_price', 'tariff_reason', 'description',
    ];

    public function editMedicine(Request $request, string $site, string $medicine, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacyCatalog($site, $request->user(), 'GET', 'medicines/'.rawurlencode($medicine));
        $user = $request->user();

        return Inertia::render('SuperAdmin/Stock/MedicineEdit', [
            'targetSite' => $result['site'],
            'medicine' => data_get($result, 'data.medicine'),
            'categories' => data_get($result, 'data.categories', []),
            'suppliers' => data_get($result, 'data.suppliers', []),
            'medicineForms' => data_get($result, 'data.medicineForms', []),
            'error' => $result['ok'] ? null : $result['message'],
            'can' => [
                'change_price' => $user->can('catalog.tariffs.update'),
                'deactivate' => $user->can('medicines.delete'),
                'reactivate' => $user->can('medicines.restore'),
            ],
        ]);
    }

    public function updateMedicine(Request $request, string $site, string $medicine, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $result = $client->pharmacyCatalog($site, $request->user(), 'PUT', 'medicines/'.rawurlencode($medicine), $request->only(self::MEDICINE_FIELDS));

        if (! $result['ok']) {
            return back()->withErrors($this->siteErrors($result))->withInput();
        }

        return to_route('super-admin.stock.index')->with('status', $result['message'] ?: 'Médicament mis à jour.');
    }

    public function deactivateMedicine(Request $request, string $site, string $medicine, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond(
            $client->pharmacyCatalog($site, $request->user(), 'POST', 'medicines/'.rawurlencode($medicine).'/deactivate', $validated),
            'Médicament désactivé.',
        );
    }

    public function reactivateMedicine(Request $request, string $site, string $medicine, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond(
            $client->pharmacyCatalog($site, $request->user(), 'POST', 'medicines/'.rawurlencode($medicine).'/reactivate'),
            'Médicament réactivé.',
        );
    }

    public function categories(Request $request, string $site, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacyCatalog($site, $request->user(), 'GET', 'categories');
        $user = $request->user();

        return Inertia::render('SuperAdmin/Stock/Categories', [
            'targetSite' => $result['site'],
            'categories' => data_get($result, 'data', []),
            'error' => $result['ok'] ? null : $result['message'],
            'can' => [
                'create' => $user->can('medicine_categories.create'),
                'update' => $user->can('medicine_categories.update'),
                'delete' => $user->can('medicine_categories.delete'),
                'restore' => $user->can('medicine_categories.restore'),
            ],
        ]);
    }

    public function storeCategory(Request $request, string $site, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond(
            $client->pharmacyCatalog($site, $request->user(), 'POST', 'categories', $request->only(['code', 'name', 'description'])),
            'Famille créée.',
        );
    }

    public function updateCategory(Request $request, string $site, string $category, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond(
            $client->pharmacyCatalog($site, $request->user(), 'PUT', 'categories/'.rawurlencode($category), $request->only(['name', 'description'])),
            'Famille renommée.',
        );
    }

    public function archiveCategory(Request $request, string $site, string $category, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond(
            $client->pharmacyCatalog($site, $request->user(), 'DELETE', 'categories/'.rawurlencode($category), $validated),
            'Famille archivée.',
        );
    }

    public function restoreCategory(Request $request, string $site, string $category, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond(
            $client->pharmacyCatalog($site, $request->user(), 'POST', 'categories/'.rawurlencode($category).'/restore'),
            'Famille restaurée.',
        );
    }
}
