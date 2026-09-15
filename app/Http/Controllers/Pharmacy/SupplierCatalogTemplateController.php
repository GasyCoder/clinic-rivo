<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Services\Pharmacy\SupplierCatalogImportService;
use App\Services\Spreadsheet\ExcelWorkbook;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-098 — the blank catalog canvas: the exact columns a supplier catalog
 * must carry to be read line by line. Served identically by the clinic and
 * the portal, since it contains no site data.
 */
class SupplierCatalogTemplateController extends Controller
{
    public function __invoke(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'canevas-catalogue-fournisseur',
            'Catalogue fournisseur',
            array_values(SupplierCatalogImportService::HEADER_LABELS),
            [
                ['AMOX-500', 'Amoxicilline 500 mg', 'Boîte de 12 gélules', '4500'],
                ['PARA-1G', 'Paracétamol 1 g', 'Boîte de 8 comprimés', '1200'],
            ],
        );
    }
}
