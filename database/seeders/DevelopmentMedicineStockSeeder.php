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

/**
 * Médicaments, lots et prix **fictifs** pour essayer la Pharmacie, les
 * ordonnances, l'hospitalisation et la Maternité en local (ADR-086, ADR-163).
 *
 * Appelé par `DevelopmentSeeder` après un `migrate:fresh --seed`, et refusé
 * hors `local` / `testing`. Il ne crée que ce qui manque : un médicament déjà
 * présent — modifié, archivé, entamé par des délivrances — n'est jamais
 * retouché, si bien qu'on peut le relancer sans perdre une seule décision ni
 * un seul mouvement réel. Pour repartir de zéro : `php artisan rivo:pharmacy-reset`.
 */
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
        ['code' => 'DEV-CARDIO', 'name' => 'Cardiovasculaire', 'description' => 'Catégorie fictive pour les essais locaux.'],
        ['code' => 'DEV-OBST', 'name' => 'Obstétrique & contraception', 'description' => 'Catégorie fictive pour les essais locaux.'],
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
        // ADR-163 (2026-09-21) — de quoi essayer l'hospitalisation, la
        // Maternité et les Soins : injectables, solutés, produits de
        // contraception et petit matériel. Noms et prix fictifs.
        ['code' => 'DEV-PARA-INJ', 'name' => 'Paracétamol injectable 1 g', 'generic' => 'Paracétamol', 'form' => MedicineForm::Injectable, 'strength' => '1 g / 100 ml', 'unit' => 'flacon', 'quantity' => 40, 'minimum' => 10, 'expiry_months' => 14, 'price' => 4500, 'prescription' => true, 'category' => 'DEV-ANTALG', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000019'],
        ['code' => 'DEV-TRAMADOL-50', 'name' => 'Tramadol 50 mg', 'generic' => 'Tramadol', 'form' => MedicineForm::Tablet, 'strength' => '50 mg', 'unit' => 'gélule', 'quantity' => 30, 'minimum' => 10, 'expiry_months' => 12, 'price' => 1200, 'prescription' => true, 'category' => 'DEV-ANTALG', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000020'],
        ['code' => 'DEV-DICLO-INJ', 'name' => 'Diclofénac injectable 75 mg', 'generic' => 'Diclofénac', 'form' => MedicineForm::Injectable, 'strength' => '75 mg / 3 ml', 'unit' => 'ampoule', 'quantity' => 30, 'minimum' => 10, 'expiry_months' => 12, 'price' => 2500, 'prescription' => true, 'category' => 'DEV-ANTALG', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000021'],
        ['code' => 'DEV-AMPI-1G', 'name' => 'Ampicilline 1 g injectable', 'generic' => 'Ampicilline', 'form' => MedicineForm::Injectable, 'strength' => '1 g', 'unit' => 'flacon', 'quantity' => 20, 'minimum' => 8, 'expiry_months' => 12, 'price' => 5000, 'prescription' => true, 'category' => 'DEV-ANTIINF', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000022'],
        ['code' => 'DEV-GENTA-80', 'name' => 'Gentamicine 80 mg injectable', 'generic' => 'Gentamicine', 'form' => MedicineForm::Injectable, 'strength' => '80 mg / 2 ml', 'unit' => 'ampoule', 'quantity' => 25, 'minimum' => 10, 'expiry_months' => 12, 'price' => 2000, 'prescription' => true, 'category' => 'DEV-ANTIINF', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000023'],
        ['code' => 'DEV-ARTESUNATE', 'name' => 'Artésunate 60 mg injectable', 'generic' => 'Artésunate', 'form' => MedicineForm::Injectable, 'strength' => '60 mg', 'unit' => 'flacon', 'quantity' => 20, 'minimum' => 10, 'expiry_months' => 12, 'price' => 12000, 'prescription' => true, 'category' => 'DEV-ANTIINF', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000024'],
        ['code' => 'DEV-METOCLO-INJ', 'name' => 'Métoclopramide injectable 10 mg', 'generic' => 'Métoclopramide', 'form' => MedicineForm::Injectable, 'strength' => '10 mg / 2 ml', 'unit' => 'ampoule', 'quantity' => 30, 'minimum' => 10, 'expiry_months' => 12, 'price' => 1500, 'prescription' => true, 'category' => 'DEV-DIGEST', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000025'],
        ['code' => 'DEV-OMEP-INJ', 'name' => 'Oméprazole 40 mg injectable', 'generic' => 'Oméprazole', 'form' => MedicineForm::Injectable, 'strength' => '40 mg', 'unit' => 'flacon', 'quantity' => 15, 'minimum' => 5, 'expiry_months' => 12, 'price' => 6000, 'prescription' => true, 'category' => 'DEV-DIGEST', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000026'],
        ['code' => 'DEV-FUROS-INJ', 'name' => 'Furosémide 20 mg injectable', 'generic' => 'Furosémide', 'form' => MedicineForm::Injectable, 'strength' => '20 mg / 2 ml', 'unit' => 'ampoule', 'quantity' => 20, 'minimum' => 8, 'expiry_months' => 12, 'price' => 1500, 'prescription' => true, 'category' => 'DEV-CARDIO', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000027'],
        ['code' => 'DEV-AMLO-5', 'name' => 'Amlodipine 5 mg', 'generic' => 'Amlodipine', 'form' => MedicineForm::Tablet, 'strength' => '5 mg', 'unit' => 'comprimé', 'quantity' => 60, 'minimum' => 20, 'expiry_months' => 18, 'price' => 600, 'prescription' => true, 'category' => 'DEV-CARDIO', 'supplier' => 'DEV-DISTRIB', 'barcode' => '990000000028'],
        ['code' => 'DEV-DEXA-INJ', 'name' => 'Dexaméthasone 4 mg injectable', 'generic' => 'Dexaméthasone', 'form' => MedicineForm::Injectable, 'strength' => '4 mg / ml', 'unit' => 'ampoule', 'quantity' => 30, 'minimum' => 10, 'expiry_months' => 12, 'price' => 1500, 'prescription' => true, 'category' => 'DEV-RESP', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000029'],
        ['code' => 'DEV-OXYTO-10', 'name' => 'Oxytocine 10 UI injectable', 'generic' => 'Oxytocine', 'form' => MedicineForm::Injectable, 'strength' => '10 UI / ml', 'unit' => 'ampoule', 'quantity' => 20, 'minimum' => 10, 'expiry_months' => 10, 'price' => 2500, 'prescription' => true, 'category' => 'DEV-OBST', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000030'],
        ['code' => 'DEV-SAYANA', 'name' => 'Contraceptif injectable (Sayana Press)', 'generic' => 'Médroxyprogestérone', 'form' => MedicineForm::Injectable, 'strength' => '104 mg / 0,65 ml', 'unit' => 'dose', 'quantity' => 15, 'minimum' => 5, 'expiry_months' => 18, 'price' => 3000, 'prescription' => true, 'category' => 'DEV-OBST', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000031'],
        ['code' => 'DEV-DIU-CU', 'name' => 'Dispositif intra-utérin au cuivre', 'generic' => null, 'form' => MedicineForm::Other, 'strength' => 'TCu 380A', 'unit' => 'unité', 'quantity' => 10, 'minimum' => 3, 'expiry_months' => 36, 'price' => 8000, 'prescription' => false, 'category' => 'DEV-OBST', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000032'],
        ['code' => 'DEV-IMPLANT', 'name' => 'Implant contraceptif', 'generic' => 'Étonogestrel', 'form' => MedicineForm::Other, 'strength' => '68 mg', 'unit' => 'unité', 'quantity' => 8, 'minimum' => 3, 'expiry_months' => 36, 'price' => 15000, 'prescription' => true, 'category' => 'DEV-OBST', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000033'],
        ['code' => 'DEV-RINGER-500', 'name' => 'Ringer lactate', 'generic' => 'Ringer lactate', 'form' => MedicineForm::Injectable, 'strength' => '500 ml', 'unit' => 'poche', 'quantity' => 30, 'minimum' => 10, 'expiry_months' => 18, 'price' => 8000, 'prescription' => true, 'category' => 'DEV-CONS', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000034'],
        ['code' => 'DEV-G5-500', 'name' => 'Glucose 5 %', 'generic' => 'Glucose', 'form' => MedicineForm::Injectable, 'strength' => '500 ml', 'unit' => 'poche', 'quantity' => 30, 'minimum' => 10, 'expiry_months' => 18, 'price' => 7000, 'prescription' => true, 'category' => 'DEV-CONS', 'supplier' => 'DEV-CENTRALE', 'barcode' => '990000000035'],
        ['code' => 'DEV-SERINGUE-5', 'name' => 'Seringue 5 ml', 'generic' => null, 'form' => MedicineForm::ParapharmacyConsumable, 'strength' => '5 ml', 'unit' => 'unité', 'quantity' => 200, 'minimum' => 50, 'expiry_months' => 36, 'price' => 300, 'prescription' => false, 'category' => 'DEV-CONS', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000036'],
        ['code' => 'DEV-CATHETER-20', 'name' => 'Cathéter IV 20G', 'generic' => null, 'form' => MedicineForm::ParapharmacyConsumable, 'strength' => '20G', 'unit' => 'unité', 'quantity' => 80, 'minimum' => 20, 'expiry_months' => 36, 'price' => 1500, 'prescription' => false, 'category' => 'DEV-CONS', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000037'],
        ['code' => 'DEV-PERFUSEUR', 'name' => 'Perfuseur', 'generic' => null, 'form' => MedicineForm::ParapharmacyConsumable, 'strength' => 'Standard', 'unit' => 'unité', 'quantity' => 60, 'minimum' => 20, 'expiry_months' => 36, 'price' => 1200, 'prescription' => false, 'category' => 'DEV-CONS', 'supplier' => 'DEV-LOCAL', 'barcode' => '990000000038'],
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
                // Un médicament déjà présent n'est jamais retouché : ni sa fiche,
                // ni son prix, ni son stock, ni son archivage. Relancer le
                // seeder complète ce qui manque, il n'écrase aucune décision ni
                // aucun mouvement réel (ADR-086, ADR-163).
                if (CatalogItem::query()->withTrashed()->where('code', $definition['code'])->exists()) {
                    $rows[] = [$definition['name'], '—', $definition['form']->label(), 'déjà présent', '—', '—'];

                    continue;
                }

                $item = CatalogItem::query()->create([
                    'code' => $definition['code'],
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
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);

                $this->createTariff($item, $actor, $definition['price']);

                $medicine = Medicine::query()->create([
                    'catalog_item_id' => $item->getKey(),
                    'medicine_category_id' => $categories[$definition['category']]->getKey(),
                    'generic_name' => $definition['generic'],
                    'form' => $definition['form'],
                    'strength' => $definition['strength'],
                    'manufacturer' => 'Laboratoire Démo',
                    'barcode' => $definition['barcode'],
                    'minimum_stock' => $definition['minimum'],
                    'prescription_required' => $definition['prescription'],
                    'active' => true,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);

                $supplier = $suppliers[$definition['supplier']];
                $medicine->suppliers()->syncWithoutDetaching([$supplier->getKey()]);

                $usableLot = null;

                if ($definition['quantity'] > 0) {
                    $usableLot = $this->createLot(
                        $medicine,
                        $supplier,
                        $actor,
                        lotNumber: "{$definition['code']}-LOT-01",
                        quantity: $definition['quantity'],
                        expiresAt: $today->addMonths($definition['expiry_months']),
                    );
                }

                if ($definition['code'] === 'DEV-PARA-500') {
                    $this->createLot(
                        $medicine,
                        $supplier,
                        $actor,
                        lotNumber: 'DEV-PARA-500-EXPIRED',
                        quantity: 7,
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
            // Une famille existante — renommée ou archivée par la Pharmacie —
            // reste telle quelle.
            $categories[$definition['code']] = MedicineCategory::query()->withTrashed()->firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ],
            );
        }

        return $categories;
    }

    /** @return array<string, MedicineSupplier> */
    private function synchronizeSuppliers(User $actor): array
    {
        $suppliers = [];

        foreach (self::SUPPLIERS as $definition) {
            $suppliers[$definition['code']] = MedicineSupplier::query()->withTrashed()->firstOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()],
            );
        }

        return $suppliers;
    }

    private function createTariff(CatalogItem $item, User $actor, int $amount): CatalogTariff
    {
        return $item->tariffs()->create([
            'tariff_category' => CatalogTariffCategory::Standard,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif fictif initialisé par le seeder local de démonstration.',
            'created_by' => $actor->getKey(),
        ]);
    }

    private function createLot(
        Medicine $medicine,
        MedicineSupplier $supplier,
        User $actor,
        string $lotNumber,
        int $quantity,
        CarbonImmutable $expiresAt,
    ): MedicineLot {
        $lot = MedicineLot::query()->create([
            'medicine_id' => $medicine->getKey(),
            'lot_number' => $lotNumber,
            'medicine_supplier_id' => $supplier->getKey(),
            'received_at' => now()->subMonths(2),
            'expires_at' => $expiresAt,
            'quantity_on_hand' => $quantity,
            'active' => true,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);

        PharmacyStockMovement::query()->create([
            'medicine_lot_id' => $lot->getKey(),
            'medicine_supplier_id' => $supplier->getKey(),
            'type' => PharmacyStockMovementType::Opening,
            'quantity_delta' => $quantity,
            'balance_after' => $quantity,
            'source_key' => sprintf('DEV-SEED:%s', $lot->uuid),
            'origin' => $supplier->name,
            'destination' => 'Stock Pharmacie — '.config('rivo.site.name'),
            'reason' => 'Stock initial fictif de développement.',
            'occurred_at' => now(),
            'performed_by' => $actor->getKey(),
        ]);

        return $lot;
    }
}
