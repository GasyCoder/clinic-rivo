<?php

use App\Services\Pharmacy\SupplierCatalogImportService;

/*
 * ADR-098 — a portal page about one clinic must never overwrite the shared
 * `site` prop: the layout reads its `type` to choose the portal menu, and a
 * clinic site in its place switched the Super Admin into that clinic's menu.
 */
it('keeps the target clinic out of the shared site prop on portal pages', function () {
    $controllers = [
        app_path('Http/Controllers/SuperAdmin/PharmacySupplierController.php'),
        app_path('Http/Controllers/SuperAdmin/PharmacyProcurementController.php'),
        app_path('Http/Controllers/SuperAdmin/DocumentTemplateController.php'),
    ];

    foreach ($controllers as $controller) {
        $source = file_get_contents($controller);

        // Inertia::render(...) props only; a session payload may keep its own `site` key.
        preg_match_all('/Inertia::render\(.*?\]\);/s', $source, $renders);

        foreach ($renders[0] as $render) {
            expect($render)->not->toContain("'site' =>");
        }
    }
});

it('serves the blank supplier catalog canvas with the expected columns', function () {
    expect(array_values(SupplierCatalogImportService::HEADER_LABELS))
        ->toBe(['Référence', 'Médicament', 'Présentation', 'Prix fournisseur']);
});
