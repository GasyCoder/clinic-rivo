<?php

namespace Tests\Feature\Pharmacy;

use App\Actions\Pharmacy\ArchiveSupplierCatalogAction;
use App\Actions\Pharmacy\ArchiveSupplierCatalogItemAction;
use App\Actions\Pharmacy\LinkSupplierCatalogItemAction;
use App\Actions\Pharmacy\RestoreSupplierCatalogAction;
use App\Actions\Pharmacy\RestoreSupplierCatalogItemAction;
use App\Actions\Pharmacy\SetMedicineSupplierOfferAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierOfferComparison;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-183 — un prix d'achat venu d'un catalogue fournisseur ne survit pas au
 * retrait de ce catalogue.
 *
 * Le constat du propriétaire : Pharmalife, dont les catalogues étaient à la
 * corbeille, restait proposé au comparateur avec cinq produits — les prix que
 * ses lignes avaient créés, toujours « en cours ». Retirer le catalogue les
 * clôt désormais ; le restaurer les rétablit, sauf si une décision prise
 * depuis les a remplacés. Rien n'est jamais supprimé.
 */
class SupplierCatalogPriceWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private CatalogActor $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $this->buyer = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
        $this->buyer->permissions()->attach(
            Permission::query()->whereIn('name', [
                'supplier_catalogs.delete', 'supplier_catalogs.restore',
                'medicine_supplier_offers.create', 'medicine_supplier_offers.update',
            ])->pluck('id'),
            ['effect' => 'allow'],
        );
        $this->actor = CatalogActor::fromUser($this->buyer->fresh());
    }

    public function test_a_catalogue_in_the_trash_no_longer_offers_its_prices_to_the_comparison(): void
    {
        $alcohol = $this->medicine('Alcool 1l 70°');
        $pharmalife = $this->supplier('PHARMALIFE', 'Pharmalife');
        $line = $this->catalogLine($pharmalife, 'Alcool 1l 70°', '7400');
        app(LinkSupplierCatalogItemAction::class)->execute($line, $alcohol, 'Même produit.', $this->actor);

        $this->assertSame(1, $this->offersCountOf($pharmalife));

        app(ArchiveSupplierCatalogAction::class)->execute($line->catalog, 'Tarif périmé', $this->actor);

        // Plus rien de Pharmalife au comparateur…
        $this->assertSame(0, $this->offersCountOf($pharmalife));
        $this->assertSame([], app(SupplierOfferComparison::class)->forSite()['medicines']);

        // … mais le prix n'est pas effacé : il est clos, et reste lisible.
        $offer = MedicineSupplierOffer::query()->sole();
        $this->assertNull($offer->active_key);
        $this->assertNotNull($offer->effective_until);
        $this->assertSame($this->buyer->id, $offer->ended_by);
    }

    public function test_restoring_the_catalogue_brings_its_price_back_as_a_new_version(): void
    {
        $alcohol = $this->medicine('Alcool 1l 70°');
        $supplier = $this->supplier('PHARMALIFE', 'Pharmalife');
        $line = $this->catalogLine($supplier, 'Alcool 1l 70°', '7400');
        app(LinkSupplierCatalogItemAction::class)->execute($line, $alcohol, 'Même produit.', $this->actor);
        $catalog = $line->catalog;

        app(ArchiveSupplierCatalogAction::class)->execute($catalog, 'Supprimé par erreur', $this->actor);
        app(RestoreSupplierCatalogAction::class)->execute(SupplierCatalog::withTrashed()->findOrFail($catalog->id), $this->actor);

        $current = MedicineSupplierOffer::query()->where('active_key', 'CURRENT')->sole();
        $this->assertSame('7400.00', $current->quoted_price);
        $this->assertSame($line->id, $current->supplier_catalog_item_id);
        // L'ancienne version reste là, close : l'histoire n'est pas réécrite.
        $this->assertSame(2, MedicineSupplierOffer::query()->where('medicine_id', $alcohol->id)->count());
        $this->assertSame(1, $this->offersCountOf($supplier));
    }

    public function test_a_price_set_since_the_withdrawal_is_never_overwritten_by_a_restore(): void
    {
        $alcohol = $this->medicine('Alcool 1l 70°');
        $supplier = $this->supplier('PHARMALIFE', 'Pharmalife');
        $line = $this->catalogLine($supplier, 'Alcool 1l 70°', '7400');
        app(LinkSupplierCatalogItemAction::class)->execute($line, $alcohol, 'Même produit.', $this->actor);
        $catalog = $line->catalog;

        app(ArchiveSupplierCatalogAction::class)->execute($catalog, 'Tarif périmé', $this->actor);
        // Le fournisseur a donné un nouveau prix par téléphone, saisi à la main.
        app(SetMedicineSupplierOfferAction::class)->execute($alcohol, $supplier, '6900', 'Tarif négocié', $this->actor);
        app(RestoreSupplierCatalogAction::class)->execute(SupplierCatalog::withTrashed()->findOrFail($catalog->id), $this->actor);

        $current = MedicineSupplierOffer::query()->where('active_key', 'CURRENT')->sole();
        $this->assertSame('6900.00', $current->quoted_price);
    }

    public function test_a_manual_price_of_the_same_supplier_survives_the_withdrawal_of_a_catalogue(): void
    {
        $alcohol = $this->medicine('Alcool 1l 70°');
        $cotton = $this->medicine('Coton hydrophile');
        $supplier = $this->supplier('PHARMALIFE', 'Pharmalife');
        $line = $this->catalogLine($supplier, 'Alcool 1l 70°', '7400');
        app(LinkSupplierCatalogItemAction::class)->execute($line, $alcohol, 'Même produit.', $this->actor);
        app(SetMedicineSupplierOfferAction::class)->execute($cotton, $supplier, '1500', 'Tarif négocié', $this->actor);

        app(ArchiveSupplierCatalogAction::class)->execute($line->catalog, 'Tarif périmé', $this->actor);

        // Seul le prix que ce catalogue avait fourni cesse d'être en cours.
        $current = MedicineSupplierOffer::query()->where('active_key', 'CURRENT')->sole();
        $this->assertSame($cotton->id, $current->medicine_id);
    }

    public function test_a_single_line_withdrawn_and_restored_takes_its_price_with_it(): void
    {
        $alcohol = $this->medicine('Alcool 1l 70°');
        $supplier = $this->supplier('PHARMALIFE', 'Pharmalife');
        $line = $this->catalogLine($supplier, 'Alcool 1l 70°', '7400');
        app(LinkSupplierCatalogItemAction::class)->execute($line, $alcohol, 'Même produit.', $this->actor);

        app(ArchiveSupplierCatalogItemAction::class)->execute($line, 'Ligne inventée par l’import', $this->actor);
        $this->assertSame(0, MedicineSupplierOffer::query()->where('active_key', 'CURRENT')->count());

        app(RestoreSupplierCatalogItemAction::class)->execute($line, $this->actor);
        $this->assertSame('7400.00', MedicineSupplierOffer::query()->where('active_key', 'CURRENT')->sole()->quoted_price);
    }

    public function test_a_line_restored_while_its_catalogue_stays_in_the_trash_brings_no_price_back(): void
    {
        $alcohol = $this->medicine('Alcool 1l 70°');
        $supplier = $this->supplier('PHARMALIFE', 'Pharmalife');
        $line = $this->catalogLine($supplier, 'Alcool 1l 70°', '7400');
        app(LinkSupplierCatalogItemAction::class)->execute($line, $alcohol, 'Même produit.', $this->actor);

        app(ArchiveSupplierCatalogItemAction::class)->execute($line, 'Ligne en double', $this->actor);
        app(ArchiveSupplierCatalogAction::class)->execute($line->catalog, 'Tarif périmé', $this->actor);
        app(RestoreSupplierCatalogItemAction::class)->execute($line, $this->actor);

        $this->assertSame(0, MedicineSupplierOffer::query()->where('active_key', 'CURRENT')->count());
    }

    // ------------------------------------------------------------------ outils

    private function offersCountOf(MedicineSupplier $supplier): int
    {
        return collect(app(SupplierOfferComparison::class)->forSite()['suppliers'])
            ->firstWhere('uuid', $supplier->uuid)['offers_count'];
    }

    private function supplier(string $code, string $name): MedicineSupplier
    {
        return MedicineSupplier::query()->create(['code' => $code, 'name' => $name]);
    }

    private function catalogLine(MedicineSupplier $supplier, string $label, ?string $price): SupplierCatalogItem
    {
        $catalog = SupplierCatalog::query()->create([
            'medicine_supplier_id' => $supplier->id,
            'original_name' => 'catalogue.xlsx',
            'path' => 'catalogues/'.Str::uuid().'.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 10,
            'kind' => 'EXCEL',
            'active_key' => 'ACTIVE',
            'created_by' => $this->buyer->id,
            'updated_by' => $this->buyer->id,
        ]);

        return SupplierCatalogItem::query()->create([
            'supplier_catalog_id' => $catalog->id,
            'reference' => 'REF-'.(SupplierCatalogItem::query()->withTrashed()->count() + 1),
            'medicine_label' => $label,
            'presentation' => 'Flacon',
            'supplier_price' => $price,
            'row_number' => 1,
            'created_by' => $this->buyer->id,
        ]);
    }

    private function medicine(string $name): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.str_pad((string) (CatalogItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'Flacon',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->buyer->id,
            'updated_by' => $this->buyer->id,
        ]);

        return Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $name,
            'form' => MedicineForm::Liquid,
            'active' => true,
            'created_by' => $this->buyer->id,
            'updated_by' => $this->buyer->id,
        ]);
    }
}
