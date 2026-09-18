<?php

namespace Database\Seeders;

use App\Actions\Pharmacy\ActivateSupplierCatalogAction;
use App\Actions\Pharmacy\CancelPurchaseOrderAction;
use App\Actions\Pharmacy\CreatePurchaseOrderAction;
use App\Actions\Pharmacy\LinkSupplierCatalogItemAction;
use App\Actions\Pharmacy\ReceiveGoodsAction;
use App\Actions\Pharmacy\RecordSupplierInvoiceAction;
use App\Actions\Pharmacy\SetMedicineSupplierOfferAction;
use App\Actions\Pharmacy\SubmitPurchaseOrderAction;
use App\Actions\Pharmacy\UploadSupplierCatalogAction;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierCatalogImportService;
use Database\Seeders\Concerns\LocalOnly;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * ADR-086/089 — local simulation of the whole supply chain on top of the
 * demo stock: supplier prices (several suppliers, price history), catalogs
 * (an active imported Excel, an older one, a PDF), purchase orders in every
 * state, receptions and a supplier invoice.
 *
 * Every write goes through the real Actions, so the simulated data obeys the
 * same rules as real work (frozen order prices, partial receptions entering
 * stock as immutable movements…). It runs once: an existing simulation is
 * never duplicated. Nothing here creates a patient, an invoice to a patient
 * or a payment.
 */
class DevelopmentProcurementSeeder extends Seeder
{
    use LocalOnly;

    private const MARKER = '[SIMULATION]';

    /** The whole procurement chain, granted by name to the local Pharmacy test accounts. */
    private const PHARMACY_TEST_PERMISSIONS = [
        'medicine_suppliers.view',
        'supplier_catalogs.view', 'supplier_catalogs.create', 'supplier_catalogs.update',
        'medicine_supplier_offers.view', 'medicine_supplier_offers.create', 'medicine_supplier_offers.update',
        'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update',
        'purchase_orders.submit', 'purchase_orders.cancel',
        'goods_receipts.view', 'goods_receipts.create',
        'supplier_invoices.view', 'supplier_invoices.create',
    ];

    /** medicine code, supplier code, earlier price (or null), current price */
    private const OFFERS = [
        ['DEV-PARA-500', 'DEV-DISTRIB', 220, 250],
        ['DEV-IBU-400', 'DEV-DISTRIB', null, 400],
        ['DEV-OMEP-20', 'DEV-DISTRIB', null, 550],
        ['DEV-SIROP', 'DEV-DISTRIB', 2100, 2300],
        ['DEV-LORAT-10', 'DEV-DISTRIB', null, 450],
        ['DEV-AMOX-500', 'DEV-DISTRIB', null, 680],
        ['DEV-SUPPO', 'DEV-LOCAL', null, 700],
        ['DEV-SRO', 'DEV-LOCAL', null, 700],
        ['DEV-CHARBON', 'DEV-LOCAL', null, 300],
        ['DEV-CREME', 'DEV-LOCAL', 3000, 3200],
        ['DEV-POVIDONE', 'DEV-LOCAL', null, 2800],
        ['DEV-CONSOMMABLE', 'DEV-LOCAL', null, 1500],
        ['DEV-EPUISE', 'DEV-LOCAL', null, 14000],
    ];

    /** Centrale's catalogs: [reference, label, presentation, price, clinic medicine code or null] */
    private const CENTRALE_JULY = [
        ['CP-1001', 'Amoxicilline 500 mg', 'Boîte de 12 gélules', 650, null],
        ['CP-1002', 'Ceftriaxone 1 g', 'Flacon', 4400, null],
        ['CP-1003', 'Métronidazole 500 mg', 'Boîte de 20', 480, null],
    ];

    private const CENTRALE_SEPTEMBER = [
        ['CP-1001', 'Amoxicilline 500 mg', 'Boîte de 12 gélules', 600, 'DEV-AMOX-500'],
        ['CP-1002', 'Ceftriaxone 1 g', 'Flacon', 4200, 'DEV-CEFTRI-1G'],
        ['CP-1003', 'Métronidazole 500 mg', 'Boîte de 20', 450, 'DEV-METRO-500'],
        ['CP-1004', 'Salbutamol inhalateur', '100 µg / dose', 7000, 'DEV-SALBU'],
        ['CP-1005', 'Chlorure de sodium 0,9 %', 'Poche 500 ml', 3800, 'DEV-NACL-500'],
        ['CP-1006', 'Collyre antiseptique', 'Flacon 10 ml', 2900, 'DEV-COLLYRE'],
        ['CP-1007', 'Paracétamol 500 mg', 'Boîte de 20', 230, 'DEV-PARA-500'],
        // Left unlinked on purpose: « Ajouter au catalogue clinique » can be tried on them.
        ['CP-2001', 'Azithromycine 500 mg', 'Boîte de 3', 2600, null],
        ['CP-2002', 'Fer + acide folique', 'Boîte de 30', 900, null],
    ];

    public function run(): void
    {
        $this->ensureLocal();

        if (config('rivo.site.type') !== 'clinic') {
            throw new LogicException('L’approvisionnement Pharmacie existe uniquement sur un site opérationnel.');
        }

        $actor = User::query()->where('email', 'user@rivo.test')->first()
            ?? throw new RuntimeException('Lancez d’abord DevelopmentTestAccountSeeder (compte user@rivo.test).');

        $this->grantPharmacyTestAccounts();

        if (PurchaseOrder::query()->where('notes', 'like', '%'.self::MARKER.'%')->exists()) {
            $this->command?->info('Simulation d’approvisionnement déjà présente : rien n’est recréé.');

            return;
        }

        $suppliers = MedicineSupplier::query()->whereIn('code', ['DEV-DISTRIB', 'DEV-CENTRALE', 'DEV-LOCAL'])->get()->keyBy('code');
        $medicines = Medicine::query()->with('catalogItem:id,code')->get()
            ->filter(fn (Medicine $medicine) => $medicine->catalogItem !== null)
            ->keyBy(fn (Medicine $medicine) => $medicine->catalogItem->code);

        if ($suppliers->count() < 3 || ! $medicines->has('DEV-AMOX-500')) {
            throw new RuntimeException('Lancez d’abord DevelopmentMedicineStockSeeder (médicaments et fournisseurs de démonstration).');
        }

        DB::transaction(function () use ($actor, $suppliers, $medicines): void {
            $this->offers($actor, $suppliers, $medicines);
            $this->catalogs($actor, $suppliers, $medicines);
            $this->purchaseOrders($actor, $suppliers, $medicines);
        });

        $this->command?->table(
            ['Élément simulé', 'Détail'],
            [
                ['Prix fournisseurs', count(self::OFFERS).' prix, dont 4 révisés (historique) et 3 médicaments à plusieurs fournisseurs'],
                ['Catalogues', 'Centrale : juillet (ancien) + septembre (actif, 9 lignes dont 2 à ajouter au catalogue) · Local : PDF'],
                ['Commandes', 'brouillon · passée · partiellement reçue · reçue avec facture · annulée'],
                ['Comptes Pharmacie de test', 'droits d’approvisionnement accordés nommément'],
            ],
        );
        $this->command?->warn('Données fictives réservées à la simulation locale : la Pharmacie n’encaisse toujours rien.');
    }

    private function grantPharmacyTestAccounts(): void
    {
        $permissionIds = Permission::query()->whereIn('name', self::PHARMACY_TEST_PERMISSIONS)->pluck('id');
        $accounts = User::query()
            ->whereHas('role', fn ($query) => $query->where('code', 'PHARMACY'))
            ->where('email', 'like', '%@rivo.test')
            ->pluck('id');
        $now = now();

        // insertOrIgnore: an existing decision on an account — a DENY in
        // particular — is never overwritten (ADR-086).
        DB::table('user_permissions')->insertOrIgnore($accounts->crossJoin($permissionIds)->map(fn (array $pair): array => [
            'user_id' => $pair[0],
            'permission_id' => $pair[1],
            'effect' => 'allow',
            'source' => 'MANUAL',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    private function offers(User $actor, Collection $suppliers, Collection $medicines): void
    {
        $action = app(SetMedicineSupplierOfferAction::class);

        foreach (self::OFFERS as [$medicineCode, $supplierCode, $earlier, $current]) {
            $medicine = $medicines->get($medicineCode);

            if (! $medicine) {
                continue;
            }

            if ($earlier !== null) {
                $action->execute($medicine, $suppliers[$supplierCode], (string) $earlier, 'Tarif fictif du trimestre précédent.', $actor, "{$supplierCode}-{$medicineCode}");
            }

            $action->execute($medicine, $suppliers[$supplierCode], (string) $current, $earlier !== null ? 'Hausse fictive du fournisseur.' : 'Tarif fictif de démonstration.', $actor, "{$supplierCode}-{$medicineCode}");
        }
    }

    private function catalogs(User $actor, Collection $suppliers, Collection $medicines): void
    {
        $upload = app(UploadSupplierCatalogAction::class);
        $importer = app(SupplierCatalogImportService::class);
        $catalogActor = CatalogActor::fromUser($actor);
        $centrale = $suppliers['DEV-CENTRALE'];

        $july = $upload->execute($centrale, [
            'file' => $this->workbook('tarif-centrale-juillet.xlsx', self::CENTRALE_JULY),
            'catalog_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'notes' => 'Ancien tarif conservé pour l’historique (simulation).',
        ], $catalogActor);
        $importer->import($july, $catalogActor);

        $september = $upload->execute($centrale, [
            'file' => $this->workbook('tarif-centrale-septembre.xlsx', self::CENTRALE_SEPTEMBER),
            'catalog_date' => now()->startOfMonth()->toDateString(),
            'notes' => 'Tarif en vigueur (simulation).',
        ], $catalogActor);
        $importer->import($september, $catalogActor);
        app(ActivateSupplierCatalogAction::class)->execute($september, $catalogActor);

        $link = app(LinkSupplierCatalogItemAction::class);
        $references = collect(self::CENTRALE_SEPTEMBER)->keyBy(0);

        foreach ($september->items()->get() as $item) {
            $medicineCode = $references->get($item->reference)[4] ?? null;

            if ($medicineCode && $medicines->has($medicineCode)) {
                $link->execute($item, $medicines[$medicineCode], 'Rattachement du tarif Centrale de septembre (simulation).', $actor);
            }
        }

        $upload->execute($suppliers['DEV-LOCAL'], [
            'file' => $this->pdf('catalogue-fournisseur-local.pdf'),
            'catalog_date' => now()->subMonth()->toDateString(),
            'notes' => 'Catalogue PDF : consultable, jamais importé (simulation).',
        ], $catalogActor);
    }

    private function purchaseOrders(User $actor, Collection $suppliers, Collection $medicines): void
    {
        $create = app(CreatePurchaseOrderAction::class);
        $submit = app(SubmitPurchaseOrderAction::class);
        $receive = app(ReceiveGoodsAction::class);
        $orderActor = CatalogActor::fromUser($actor);

        $order = fn (string $supplier, array $lines, string $notes) => $create->execute($suppliers[$supplier], [
            'expected_delivery_at' => now()->addWeek()->toDateString(),
            'notes' => self::MARKER.' '.$notes,
            'lines' => collect($lines)->map(fn (int $quantity, string $code) => [
                'medicine_uuid' => $medicines[$code]->uuid,
                'quantity_ordered' => $quantity,
                'unit_price' => (string) ($medicines[$code]->currentOfferFor($suppliers[$supplier])->value('quoted_price') ?? 1000),
            ])->values()->all(),
        ], $orderActor);

        $receiveLines = fn (PurchaseOrder $purchaseOrder, array $quantities) => collect($quantities)->map(function (int $quantity, string $code) use ($purchaseOrder, $medicines): array {
            $line = $purchaseOrder->lines()->where('medicine_id', $medicines[$code]->id)->firstOrFail();

            return [
                'purchase_order_line_id' => $line->id,
                'quantity_received' => $quantity,
                'lot_number' => 'SIM-'.$code.'-'.$purchaseOrder->id,
                'expires_at' => now()->addMonths(18)->toDateString(),
                'unit_purchase_price' => (string) $line->unit_price,
            ];
        })->values()->all();

        // 1. Brouillon, pas encore envoyé.
        $order('DEV-DISTRIB', ['DEV-PARA-500' => 200, 'DEV-OMEP-20' => 100], 'Réassort mensuel à valider.');

        // 2. Passée, en attente de livraison.
        $submit->execute($order('DEV-CENTRALE', ['DEV-CEFTRI-1G' => 50, 'DEV-NACL-500' => 100], 'Commande urgente de solutés.'), $orderActor);

        // 3. Partiellement reçue : 80 gélules sur 100 arrivées.
        $partial = $submit->execute($order('DEV-CENTRALE', ['DEV-AMOX-500' => 100, 'DEV-SALBU' => 20], 'Livraison en deux fois annoncée.'), $orderActor);
        $receive->execute($partial->fresh(), $receiveLines($partial, ['DEV-AMOX-500' => 80, 'DEV-SALBU' => 20]), 'Reliquat de 20 Amoxicilline attendu (simulation).', $actor);

        // 4. Reçue en totalité, puis facturée.
        $received = $submit->execute($order('DEV-LOCAL', ['DEV-CREME' => 30, 'DEV-CONSOMMABLE' => 50, 'DEV-EPUISE' => 40], 'Réassort consommables.'), $orderActor);
        $receipt = $receive->execute($received->fresh(), $receiveLines($received, ['DEV-CREME' => 30, 'DEV-CONSOMMABLE' => 50, 'DEV-EPUISE' => 40]), 'Livraison complète (simulation).', $actor);
        app(RecordSupplierInvoiceAction::class)->execute($suppliers['DEV-LOCAL'], [
            'invoice_number' => 'SIM-FAC-LOCAL-001',
            'invoice_date' => now()->toDateString(),
            'purchase_order_uuid' => $received->uuid,
            'goods_receipt_uuid' => $receipt->uuid,
            'notes' => self::MARKER.' Facture de la livraison complète.',
            'lines' => $received->lines()->with('medicine.catalogItem')->get()->map(fn ($line) => [
                'medicine_uuid' => $line->medicine->uuid,
                'description' => $line->medicine->catalogItem->name,
                'quantity' => $line->quantity_ordered,
                'unit_price' => (string) $line->unit_price,
            ])->all(),
        ], $orderActor);

        // 5. Passée puis annulée.
        $cancelled = $submit->execute($order('DEV-DISTRIB', ['DEV-IBU-400' => 60], 'Doublon de commande.'), $orderActor);
        app(CancelPurchaseOrderAction::class)->execute($cancelled, 'Commande passée deux fois par erreur (simulation).', $orderActor);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function workbook(string $name, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Référence', 'Médicament', 'Présentation', 'Prix fournisseur'],
            ...array_map(fn (array $row) => array_slice($row, 0, 4), $rows),
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'rivo-sim-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rivo-sim-').'.pdf';
        $text = 'BT /F1 18 Tf 72 720 Td (Catalogue Fournisseur Medical Local - simulation) Tj ET';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            .'4 0 obj<</Length '.strlen($text).">>stream\n{$text}\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
