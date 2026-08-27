<?php

namespace Database\Seeders;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\MedicineForm;
use App\Enums\PharmacyStockMovementType;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineLot;
use App\Models\MedicineSupplier;
use App\Models\PharmacyStockMovement;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class DevelopmentMedicineStockSeeder extends Seeder
{
    /** @var array<int, array{code: string, name: string, description: string}> */
    private const CATEGORIES = [
        ['code' => 'DEV-ANTALG', 'name' => 'Antalgiques & antipyrétiques', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-ANTIINF', 'name' => 'Anti-infectieux', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-DIGEST', 'name' => 'Digestif & réhydratation', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-RESP', 'name' => 'Respiratoire & allergie', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-DERMA', 'name' => 'Dermatologie', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-OPHTA', 'name' => 'Ophtalmologie', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-ANTISEP', 'name' => 'Antiseptiques', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-CONS', 'name' => 'Solutés & consommables', 'description' => 'Catégorie fictive pour les essais locaux.'],
    ];

    /** @var array<int, array<string, string>> */
    private const SUPPLIERS = [
        [
            'code' => 'DEV-DISTRIB',
            'name' => 'Distribution Santé Démo',
            'contact_name' => 'Contact fictif',
            'phone' => '034 00 000 01',
            'email' => 'distribution@example.test',
            'address' => 'Adresse de démonstration — Madagascar',
        ],
        [
            'code' => 'DEV-CENTRALE',
            'name' => 'Centrale Pharmaceutique Démo',
            'contact_name' => 'Contact fictif',
            'phone' => '034 00 000 02',
            'email' => 'centrale@example.test',
            'address' => 'Adresse de démonstration — Madagascar',
        ],
        [
            'code' => 'DEV-LOCAL',
            'name' => 'Fournisseur Médical Local Démo',
            'contact_name' => 'Contact fictif',
            'phone' => '034 00 000 03',
            'email' => 'local@example.test',
            'address' => 'Adresse de démonstration — Madagascar',
        ],
    ];

    /**
     * Local-only fixtures covering every supported pharmaceutical form and
     * the main stock states used by the Pharmacy screens.
     *
     * @var array<int, array<string, mixed>>
     */
    private const MEDICINES = [
        ['code' => 'DEV-PARA-500', 'name' => 'Paracétamol 500 mg', 'generic' => 'Paracétamol', 'form' => MedicineForm::Tablet, 'strength' => '500 mg', 'unit' => 'comprimé', 'quantity' => 120, 'minimum' => 30, 'expiry_months' => 18, 'price' => 500, 'prescription' => false, 'category' => 'DEV-ANTALG', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000001'],
        ['code' => 'DEV-IBU-400', 'name' => 'Ibuprofène 400 mg', 'generic' => 'Ibuprofène', 'form' => MedicineForm::Tablet, 'strength' => '400 mg', 'unit' => 'comprimé', 'quantity' => 48, 'minimum' => 15, 'expiry_months' => 15, 'price' => 800, 'prescription' => false, 'category' => 'DEV-ANTALG', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000002'],
        ['code' => 'DEV-SUPPO', 'name' => 'Suppositoire glycériné', 'generic' => 'Glycérol', 'form' => MedicineForm::SuppositoryOvule, 'strength' => 'Adulte', 'unit' => 'suppositoire', 'quantity' => 20, 'minimum' => 8, 'expiry_months' => 14, 'price' => 1500, 'prescription' => false, 'category' => 'DEV-ANTALG', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000003'],
        ['code' => 'DEV-AMOX-500', 'name' => 'Amoxicilline 500 mg', 'generic' => 'Amoxicilline', 'form' => MedicineForm::Tablet, 'strength' => '500 mg', 'unit' => 'gélule', 'quantity' => 42, 'minimum' => 15, 'expiry_months' => 12, 'price' => 1200, 'prescription' => true, 'category' => 'DEV-ANTIINF', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000004'],
        ['code' => 'DEV-CEFTRI-1G', 'name' => 'Ceftriaxone 1 g', 'generic' => 'Ceftriaxone', 'form' => MedicineForm::Injectable, 'strength' => '1 g', 'unit' => 'flacon', 'quantity' => 24, 'minimum' => 10, 'expiry_months' => 12, 'price' => 8000, 'prescription' => true, 'category' => 'DEV-ANTIINF', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000005'],
        ['code' => 'DEV-METRO-500', 'name' => 'Métronidazole 500 mg', 'generic' => 'Métronidazole', 'form' => MedicineForm::Tablet, 'strength' => '500 mg', 'unit' => 'comprimé', 'quantity' => 30, 'minimum' => 12, 'expiry_months' => 11, 'price' => 1000, 'prescription' => true, 'category' => 'DEV-ANTIINF', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000006'],
        ['code' => 'DEV-OMEP-20', 'name' => 'Oméprazole 20 mg', 'generic' => 'Oméprazole', 'form' => MedicineForm::Tablet, 'strength' => '20 mg', 'unit' => 'gélule', 'quantity' => 36, 'minimum' => 12, 'expiry_months' => 16, 'price' => 1200, 'prescription' => true, 'category' => 'DEV-DIGEST', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000007'],
        ['code' => 'DEV-SRO', 'name' => 'Sels de réhydratation orale', 'generic' => 'Sels de réhydratation orale', 'form' => MedicineForm::Sachet, 'strength' => 'Formule standard', 'unit' => 'sachet', 'quantity' => 40, 'minimum' => 15, 'expiry_months' => 16, 'price' => 1500, 'prescription' => false, 'category' => 'DEV-DIGEST', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000008'],
        ['code' => 'DEV-CHARBON', 'name' => 'Charbon activé', 'generic' => 'Charbon activé', 'form' => MedicineForm::Tablet, 'strength' => '250 mg', 'unit' => 'comprimé', 'quantity' => 25, 'minimum' => 10, 'expiry_months' => 20, 'price' => 700, 'prescription' => false, 'category' => 'DEV-DIGEST', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000009'],
        ['code' => 'DEV-SIROP', 'name' => 'Paracétamol sirop', 'generic' => 'Paracétamol', 'form' => MedicineForm::Syrup, 'strength' => '120 mg / 5 ml', 'unit' => 'flacon', 'quantity' => 8, 'minimum' => 10, 'expiry_months' => 2, 'price' => 4500, 'prescription' => false, 'category' => 'DEV-RESP', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000010'],
        ['code' => 'DEV-LORAT-10', 'name' => 'Loratadine 10 mg', 'generic' => 'Loratadine', 'form' => MedicineForm::Tablet, 'strength' => '10 mg', 'unit' => 'comprimé', 'quantity' => 28, 'minimum' => 10, 'expiry_months' => 13, 'price' => 1000, 'prescription' => false, 'category' => 'DEV-RESP', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000011'],
        ['code' => 'DEV-SALBU', 'name' => 'Salbutamol inhalateur', 'generic' => 'Salbutamol', 'form' => MedicineForm::Other, 'strength' => '100 µg / dose', 'unit' => 'inhalateur', 'quantity' => 6, 'minimum' => 8, 'expiry_months' => 10, 'price' => 12000, 'prescription' => true, 'category' => 'DEV-RESP', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000012'],
        ['code' => 'DEV-CREME', 'name' => 'Crème antifongique', 'generic' => 'Clotrimazole', 'form' => MedicineForm::OintmentCream, 'strength' => '1 %', 'unit' => 'tube', 'quantity' => 9, 'minimum' => 10, 'expiry_months' => 10, 'price' => 6500, 'prescription' => false, 'category' => 'DEV-DERMA', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000013'],
        ['code' => 'DEV-COLLYRE', 'name' => 'Collyre antiseptique', 'generic' => 'Solution ophtalmique', 'form' => MedicineForm::OralAmpouleEyeDropsDrops, 'strength' => '10 ml', 'unit' => 'flacon', 'quantity' => 18, 'minimum' => 6, 'expiry_months' => 9, 'price' => 5500, 'prescription' => false, 'category' => 'DEV-OPHTA', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000014'],
        ['code' => 'DEV-POVIDONE', 'name' => 'Solution antiseptique', 'generic' => 'Povidone iodée', 'form' => MedicineForm::Liquid, 'strength' => '10 %', 'unit' => 'flacon', 'quantity' => 12, 'minimum' => 5, 'expiry_months' => 8, 'price' => 6000, 'prescription' => false, 'category' => 'DEV-ANTISEP', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000015'],
        ['code' => 'DEV-CONSOMMABLE', 'name' => 'Compresses stériles', 'generic' => null, 'form' => MedicineForm::ParapharmacyConsumable, 'strength' => 'Paquet de 10', 'unit' => 'paquet', 'quantity' => 60, 'minimum' => 20, 'expiry_months' => 24, 'price' => 3000, 'prescription' => false, 'category' => 'DEV-CONS', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000016'],
        ['code' => 'DEV-NACL-500', 'name' => 'Chlorure de sodium 0,9 %', 'generic' => 'Chlorure de sodium', 'form' => MedicineForm::Injectable, 'strength' => '500 ml', 'unit' => 'poche', 'quantity' => 22, 'minimum' => 10, 'expiry_months' => 12, 'price' => 7500, 'prescription' => true, 'category' => 'DEV-CONS', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000017'],
        ['code' => 'DEV-EPUISE', 'name' => 'Gants d’examen — rupture démo', 'generic' => null, 'form' => MedicineForm::ParapharmacyConsumable, 'strength' => 'Taille M', 'unit' => 'boîte', 'quantity' => 0, 'minimum' => 10, 'expiry_months' => 18, 'price' => 25000, 'prescription' => false, 'category' => 'DEV-CONS', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000018'],
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException(
                'DevelopmentMedicineStockSeeder est strictement interdit hors des environnements local et testing.',
            );
        }

        if (config('rivo.site.type') !== 'clinic') {
            throw new LogicException('Le stock Pharmacie existe uniquement sur un site opérationnel.');
        }

        $actor = User::query()->where('active', true)->orderBy('id')->first();

        if (! $actor) {
            throw new RuntimeException(
                'Créez d’abord les comptes locaux avec DevelopmentUserSeeder.',
            );
        }

        $today = CarbonImmutable::today();
        $rows = [];

        DB::transaction(function () use ($actor, $today, &$rows): void {
            $categories = $this->synchronizeCategories($actor);
            $suppliers = $this->synchronizeSuppliers($actor);

            foreach (self::MEDICINES as $definition) {
                $item = CatalogItem::query()->withTrashed()->firstOrNew(['code' => $definition['code']]);
                $item->fill([
                    'name' => $definition['name'],
                    'type' => CatalogItemType::Medicine,
                    'module' => CatalogModule::Pharmacy,
                    'unit' => $definition['unit'],
                    'billable' => true,
                    'stockable' => true,
                    'reception_selectable' => false,
                    'reception_routing_mode' => null,
                    'description' => 'Donnée et tarif fictifs réservés à la démonstration locale.',
                    'care_requires_allergy_check' => false,
                    'care_recommends_vitals' => false,
                    'created_by' => $item->created_by ?? $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                if ($item->trashed()) {
                    $item->restore();
                }

                $this->synchronizeTariff($item, $actor, $definition['price']);

                $medicine = Medicine::query()->withTrashed()->firstOrNew(['catalog_item_id' => $item->getKey()]);
                $medicine->fill([
                    'medicine_category_id' => $categories[$definition['category']]->getKey(),
                    'generic_name' => $definition['generic'],
                    'form' => $definition['form'],
                    'strength' => $definition['strength'],
                    'manufacturer' => 'Laboratoire Démo',
                    'barcode' => $definition['barcode'],
                    'minimum_stock' => $definition['minimum'],
                    'prescription_required' => $definition['prescription'],
                    'active' => true,
                    'created_by' => $medicine->created_by ?? $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                if ($medicine->trashed()) {
                    $medicine->restore();
                }

                $supplier = $suppliers[$definition['supplier']];
                $medicine->suppliers()->sync([$supplier->getKey()]);

                $usableLot = null;

                if ($definition['quantity'] > 0) {
                    $usableLot = $this->synchronizeLot(
                        $medicine,
                        $supplier,
                        $actor,
                        lotNumber: "{$definition['code']}-LOT-01",
                        targetQuantity: $definition['quantity'],
                        expiresAt: $today->addMonths($definition['expiry_months']),
                    );
                }

                if ($definition['code'] === 'DEV-PARA-500') {
                    $this->synchronizeLot(
                        $medicine,
                        $supplier,
                        $actor,
                        lotNumber: 'DEV-PARA-500-EXPIRED',
                        targetQuantity: 7,
                        expiresAt: $today->subMonth(),
                    );
                }

                app(MedicineStockAlertService::class)->synchronize($medicine);

                $rows[] = [
                    $item->name,
                    $categories[$definition['category']]->name,
                    $definition['form']->label(),
                    number_format($definition['price'], 0, ',', ' ').' MGA',
                    $usableLot?->quantity_on_hand ?? 0,
                    $usableLot?->expires_at->format('d/m/Y') ?? '—',
                ];
            }
        });

        $this->command?->table(
            ['Médicament', 'Catégorie', 'Forme', 'Tarif fictif', 'Stock utilisable', 'Péremption'],
            $rows,
        );
        $this->command?->warn(
            'Données et tarifs fictifs créés uniquement pour la démonstration locale ; aucun encaissement Pharmacie n’a été ajouté.',
        );
    }

    /** @return array<string, MedicineCategory> */
    private function synchronizeCategories(User $actor): array
    {
        $categories = [];

        foreach (self::CATEGORIES as $definition) {
            $category = MedicineCategory::query()->withTrashed()->firstOrNew(['code' => $definition['code']]);
            $category->fill([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'created_by' => $category->created_by ?? $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            if ($category->trashed()) {
                $category->restore();
            }

            $categories[$definition['code']] = $category;
        }

        return $categories;
    }

    /** @return array<string, MedicineSupplier> */
    private function synchronizeSuppliers(User $actor): array
    {
        $suppliers = [];

        foreach (self::SUPPLIERS as $definition) {
            $supplier = MedicineSupplier::query()->withTrashed()->firstOrNew(['code' => $definition['code']]);
            $supplier->fill([
                ...$definition,
                'created_by' => $supplier->created_by ?? $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            if ($supplier->trashed()) {
                $supplier->restore();
            }

            $suppliers[$definition['code']] = $supplier;
        }

        return $suppliers;
    }

    private function synchronizeTariff(CatalogItem $item, User $actor, int $amount): CatalogTariff
    {
        $current = $item->tariffs()
            ->where('tariff_category', CatalogTariffCategory::Standard->value)
            ->where('active_key', 'CURRENT')
            ->first();
        $formattedAmount = number_format($amount, 2, '.', '');

        if ($current && $current->amount === $formattedAmount) {
            return $current;
        }

        if ($current) {
            $current->forceFill([
                'active_key' => null,
                'effective_until' => now(),
                'ended_by' => $actor->getKey(),
            ])->save();
        }

        return $item->tariffs()->create([
            'tariff_category' => CatalogTariffCategory::Standard,
            'amount' => $formattedAmount,
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif fictif initialisé par le seeder local de démonstration.',
            'created_by' => $actor->getKey(),
        ]);
    }

    private function synchronizeLot(
        Medicine $medicine,
        MedicineSupplier $supplier,
        User $actor,
        string $lotNumber,
        int $targetQuantity,
        CarbonImmutable $expiresAt,
    ): MedicineLot {
        $lot = MedicineLot::query()->firstOrNew([
            'medicine_id' => $medicine->getKey(),
            'lot_number' => $lotNumber,
        ]);
        $previousQuantity = $lot->exists ? $lot->quantity_on_hand : 0;
        $lot->fill([
            'medicine_supplier_id' => $supplier->getKey(),
            'received_at' => $lot->received_at ?? now()->subMonths(2),
            'expires_at' => $expiresAt,
            'quantity_on_hand' => $targetQuantity,
            'active' => true,
            'created_by' => $lot->created_by ?? $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $delta = $targetQuantity - $previousQuantity;

        if ($delta !== 0) {
            PharmacyStockMovement::query()->create([
                'medicine_lot_id' => $lot->getKey(),
                'medicine_supplier_id' => $supplier->getKey(),
                'type' => $previousQuantity === 0
                    ? PharmacyStockMovementType::Opening
                    : PharmacyStockMovementType::Adjustment,
                'quantity_delta' => $delta,
                'balance_after' => $targetQuantity,
                'source_key' => sprintf('DEV-SEED:%s:%s:%d', $lot->uuid, now()->format('YmdHisv'), $targetQuantity),
                'origin' => $supplier->name,
                'destination' => 'Stock Pharmacie — '.config('rivo.site.name'),
                'reason' => $previousQuantity === 0
                    ? 'Stock initial fictif de développement.'
                    : 'Réinitialisation explicite du stock fictif de développement.',
                'occurred_at' => now(),
                'performed_by' => $actor->getKey(),
            ]);
        }

        return $lot;
    }
}
