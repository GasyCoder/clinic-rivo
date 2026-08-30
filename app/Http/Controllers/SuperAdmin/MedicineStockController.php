<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Services\SuperAdmin\StockImportWorkbookParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicineStockController extends Controller
{
    public function __invoke(Request $request, PortalSiteApiClient $client): Response
    {
        $sites = collect($client->stockForAllSites($request->user()));
        $online = $sites->where('ok', true);

        return Inertia::render('SuperAdmin/Stock/Index', [
            'sites' => $sites->values(),
            'summary' => [
                'online_sites' => $online->count(),
                'medicines' => $online->sum(fn (array $site) => data_get($site, 'data.summary.medicines', 0)),
                'available_quantity' => $online->sum(fn (array $site) => data_get($site, 'data.summary.available_quantity', 0)),
                'out_of_stock' => $online->sum(fn (array $site) => data_get($site, 'data.summary.out_of_stock', 0)),
                'expiring_soon' => $online->sum(fn (array $site) => data_get($site, 'data.summary.expiring_soon', 0)),
                'expired_lots' => $online->sum(fn (array $site) => data_get($site, 'data.summary.expired_lots', 0)),
            ],
        ]);
    }

    public function export(Request $request, PortalSiteApiClient $client, ExcelWorkbook $excel): StreamedResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in([
                'ALL',
                ...collect(config('rivo.clinics', []))->pluck('code')->all(),
            ])],
            'medicine_uuids' => ['nullable', 'array', 'max:100'],
            'medicine_uuids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $sites = collect($client->stockForAllSites($request->user()))
            ->when($validated['site_code'] !== 'ALL', fn ($items) => $items
                ->where('site.code', $validated['site_code']))
            ->where('ok', true)
            ->values();

        abort_if($sites->isEmpty(), 503, 'Aucun site sélectionné ne fournit actuellement ses données de stock.');

        $selectedUuids = collect($validated['medicine_uuids'] ?? []);
        $scope = $selectedUuids->isNotEmpty()
            ? 'selection-'.$selectedUuids->count()
            : ($validated['site_code'] === 'ALL' ? 'tous-les-sites' : mb_strtolower($validated['site_code']));

        $selectedMedicines = $sites->flatMap(fn (array $site) => collect(data_get($site, 'data.medicines', []))
            ->when($selectedUuids->isNotEmpty(), fn ($medicines) => $medicines->whereIn('uuid', $selectedUuids))
            ->map(fn (array $medicine) => ['site' => $site, 'medicine' => $medicine]));

        if ($selectedUuids->isNotEmpty() && $selectedMedicines->count() !== $selectedUuids->count()) {
            throw ValidationException::withMessages([
                'medicine_uuids' => 'Un médicament sélectionné est absent du site actuel. Aucun export n’a été généré.',
            ]);
        }

        $rows = $selectedMedicines->flatMap(function (array $selected) {
            $site = $selected['site'];
            $medicine = $selected['medicine'];
            $lots = $medicine['lots'] ?: [null];

            return collect($lots)->map(fn (?array $lot) => [
                data_get($site, 'site.code'),
                data_get($site, 'site.name'),
                $medicine['code'],
                $medicine['name'],
                $medicine['generic_name'],
                $medicine['form_label'],
                $medicine['strength'],
                $medicine['unit'],
                data_get($lot, 'lot_number'),
                data_get($lot, 'received_at'),
                data_get($lot, 'expires_at'),
                $lot ? data_get($lot, 'quantity_on_hand') : $medicine['quantity_on_hand'],
                $lot ? data_get($lot, 'reserved_quantity') : $medicine['reserved_quantity'],
                $lot ? data_get($lot, 'available_quantity') : $medicine['available_quantity'],
                data_get($lot, 'status', $medicine['status']),
            ]);
        });

        return $excel->download(
            'stock-medicaments-'.$scope.'-'.now()->format('Y-m-d-His'),
            'Stock médicaments',
            [
                'Code site', 'Site', 'Code médicament', 'Médicament', 'DCI',
                'Forme', 'Dosage', 'Unité', 'Numéro de lot', 'Date réception',
                'Date péremption', 'Quantité physique', 'Quantité réservée',
                'Quantité disponible', 'Statut',
            ],
            $rows,
        );
    }

    public function template(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'modele-import-stock-medicaments',
            'Stock à importer',
            [
                'Code médicament', 'Numéro de lot', 'Opération', 'Quantité',
                'Date réception', 'Date péremption', 'Motif',
            ],
            [
                ['PARA-500', 'LOT-2026-001', 'STOCK_INITIAL', 100, now()->format('d/m/Y'), now()->addYear()->format('d/m/Y'), 'Reprise du stock initial vérifié'],
                ['AMOX-500', 'LOT-2026-002', 'ENTREE', 50, now()->format('d/m/Y'), now()->addYear()->format('d/m/Y'), 'Livraison fournisseur vérifiée'],
            ],
        );
    }

    public function import(
        Request $request,
        PortalSiteApiClient $client,
        ExcelWorkbook $excel,
        StockImportWorkbookParser $parser,
    ): RedirectResponse {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);
        $rows = $parser->parse($excel->rows($validated['file']));
        $result = $client->importStock($validated['site_code'], $rows, $request->user());

        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [$field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages],
            )->all();

            return back()->withErrors($errors ?: ['file' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: sprintf('%d ligne(s) de stock importée(s).', count($rows)));
    }
}
