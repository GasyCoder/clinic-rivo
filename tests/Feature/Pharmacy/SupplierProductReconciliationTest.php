<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Pharmacy\SupplierOfferComparison;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-181 — deux fournisseurs ne nomment pas un produit de la même façon.
 *
 * Tant que leurs libellés restent deux lignes, leurs prix ne se comparent
 * pas, et commander la ligne du fournisseur créerait un second produit avec
 * son propre stock. Le comparateur propose le rapprochement ; il ne le décide
 * jamais, et le portail peut enfin le faire.
 */
class SupplierProductReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    private string $actorUuid;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $this->actorUuid = (string) Str::uuid();
        $this->pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
    }

    public function test_a_supplier_line_named_otherwise_is_proposed_against_the_clinic_product(): void
    {
        $medicine = $this->medicine('Alcool 1l 70°');
        $held = $this->supplier('PHARMALIFE', 'Pharmalife');
        $this->offer($held, $medicine, '7400');

        $other = $this->supplier('ARB', 'Arbiochem');
        $line = $this->catalogLine($other, 'ALCOOL ETHYLIQUE 70% 1L', '7100');

        $comparison = app(SupplierOfferComparison::class)->forSite();

        // Deux libellés, donc deux lignes : c'est justement le problème.
        $this->assertCount(2, $comparison['medicines']);
        $this->assertSame(1, $comparison['to_reconcile']);

        $unlinked = collect($comparison['medicines'])->firstWhere('in_clinic_catalog', false);
        $this->assertSame('ALCOOL ETHYLIQUE 70% 1L', $unlinked['name']);
        $this->assertSame([$medicine->uuid], array_column($unlinked['suggestions'], 'medicine_uuid'));
        // La ligne porte de quoi être rattachée : son fichier et son prix.
        $this->assertSame($line->catalog->uuid, $unlinked['quotes'][0]['supplier_catalog_uuid']);
        $this->assertTrue($unlinked['quotes'][0]['can_link']);

        // Le produit de la clinique n'est proposé à personne : il est déjà là.
        $clinic = collect($comparison['medicines'])->firstWhere('in_clinic_catalog', true);
        $this->assertSame([], $clinic['suggestions']);
    }

    public function test_a_different_volume_dose_or_gauge_is_never_proposed(): void
    {
        $this->medicine('Alcool 125ml 70°');
        $supplier = $this->supplier('ARB', 'Arbiochem');
        $this->catalogLine($supplier, 'Alcool 250ml 70°', '1750');

        $comparison = app(SupplierOfferComparison::class)->forSite();

        $this->assertSame(0, $comparison['to_reconcile']);
        foreach ($comparison['medicines'] as $row) {
            $this->assertSame([], $row['suggestions'], $row['name']);
        }
    }

    public function test_a_line_without_a_price_is_proposed_but_says_it_cannot_be_linked(): void
    {
        $this->medicine('Coton hydrophile');
        $supplier = $this->supplier('ARB', 'Arbiochem');
        $this->catalogLine($supplier, 'Coton hydrophile 500g', null);

        $comparison = app(SupplierOfferComparison::class)->forSite();
        $unlinked = collect($comparison['medicines'])->firstWhere('in_clinic_catalog', false);

        $this->assertNotEmpty($unlinked['suggestions']);
        // Rattacher crée le prix d'achat : sans prix, il n'y a rien à créer.
        $this->assertFalse($unlinked['quotes'][0]['can_link']);
    }

    public function test_the_portal_links_the_line_and_both_prices_land_on_one_row(): void
    {
        $medicine = $this->medicine('Alcool 1l 70°');
        $held = $this->supplier('PHARMALIFE', 'Pharmalife');
        $this->offer($held, $medicine, '7400');

        $other = $this->supplier('ARB', 'Arbiochem');
        $line = $this->catalogLine($other, 'ALCOOL ETHYLIQUE 70% 1L', '7100');
        $path = "/api/v1/super-admin/pharmacy/suppliers/{$other->uuid}/catalogs/{$line->catalog->uuid}/items/{$line->uuid}/link";
        $payload = ['medicine_uuid' => $medicine->uuid, 'change_reason' => 'Même produit, écrit autrement par le fournisseur.'];

        // Le droit est revu par l'Action, sur l'acteur distant.
        $this->withHeaders($this->headers(['purchase_orders.view']))->postJson($path, $payload)->assertForbidden();

        $this->withHeaders($this->headers(['medicine_supplier_offers.create']))
            ->postJson($path, $payload)
            ->assertOk();

        $this->assertSame($medicine->id, $line->refresh()->linked_medicine_id);
        // Le Super Admin distant ne devient jamais un auteur local.
        $offer = MedicineSupplierOffer::query()->where('medicine_supplier_id', $other->id)->sole();
        $this->assertNull($offer->created_by);
        $this->assertSame($this->actorUuid, $offer->external_created_by_uuid);

        $comparison = app(SupplierOfferComparison::class)->forSite();

        // Une seule ligne désormais, et les deux prix se comparent dessus.
        $this->assertCount(1, $comparison['medicines']);
        $this->assertSame(0, $comparison['to_reconcile']);
        $row = $comparison['medicines'][0];
        $this->assertCount(2, $row['quotes']);
        // Le moins cher est celui qu'on vient de rapprocher.
        $this->assertSame(7100.0, (float) $row['best_price']);
    }

    /**
     * ADR-181, amendement du 2026-09-24 — chaque ligne dit sa famille, pour
     * que l'écran filtre et range par famille au lieu de dérouler l'alphabet :
     * celle de la clinique pour un produit tenu, celle que le fournisseur
     * déclare sinon — écrite comme celle de la clinique quand elles se
     * ressemblent. Une ligne sans famille n'en reçoit pas d'inventée.
     */
    public function test_each_row_carries_its_family_in_the_clinic_spelling(): void
    {
        $syringes = MedicineCategory::query()->create([
            'code' => 'SER', 'name' => 'Seringues',
            'created_by' => $this->pharmacist->id, 'updated_by' => $this->pharmacist->id,
        ]);
        $held = $this->medicine('Seringue 5 ml');
        $held->update(['medicine_category_id' => $syringes->id]);
        $this->offer($this->supplier('ARB', 'Arbiochem'), $held, '500');
        $this->catalogLine($this->supplier('PHL', 'Pharmalife'), 'Aiguille rose 18G', '600', 'SERINGUES');
        $this->catalogLine($this->supplier('SAL', 'Salama'), 'Compresse 10x10', '300', 'Pansements');
        $this->catalogLine($this->supplier('DIS', 'Distrib'), 'Gants taille M', '100');

        $rows = collect(app(SupplierOfferComparison::class)->forSite()['medicines'])->keyBy('name');

        $this->assertSame('Seringues', $rows['Seringue 5 ml']['family']);
        $this->assertSame('Seringues', $rows['Aiguille rose 18G']['family']);
        $this->assertSame('Pansements', $rows['Compresse 10x10']['family']);
        $this->assertNull($rows['Gants taille M']['family']);
    }

    // ------------------------------------------------------------------ outils

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $this->actorUuid,
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }

    private function supplier(string $code, string $name): MedicineSupplier
    {
        return MedicineSupplier::query()->create(['code' => $code, 'name' => $name]);
    }

    private function offer(MedicineSupplier $supplier, Medicine $medicine, string $price): MedicineSupplierOffer
    {
        return MedicineSupplierOffer::query()->create([
            'medicine_id' => $medicine->id,
            'medicine_supplier_id' => $supplier->id,
            'quoted_price' => $price,
            'effective_from' => now()->toDateString(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Prix du catalogue en vigueur.',
            'created_by' => $this->pharmacist->id,
        ]);
    }

    private function catalogLine(MedicineSupplier $supplier, string $label, ?string $price, ?string $family = null): SupplierCatalogItem
    {
        $catalog = SupplierCatalog::query()->create([
            'medicine_supplier_id' => $supplier->id,
            'original_name' => 'catalogue.xlsx',
            'path' => 'catalogues/'.Str::uuid().'.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 10,
            'kind' => 'EXCEL',
            'active_key' => 'ACTIVE',
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        return SupplierCatalogItem::query()->create([
            'supplier_catalog_id' => $catalog->id,
            'reference' => 'REF-'.SupplierCatalogItem::query()->count(),
            'medicine_label' => $label,
            'presentation' => 'Flacon',
            'supplier_price' => $price,
            'family_label' => $family,
            'row_number' => 1,
            'created_by' => $this->pharmacist->id,
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
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        return Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $name,
            'form' => MedicineForm::Liquid,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }
}
