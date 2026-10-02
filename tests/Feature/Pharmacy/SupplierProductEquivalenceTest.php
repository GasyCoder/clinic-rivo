<?php

namespace Tests\Feature\Pharmacy;

use App\Actions\Pharmacy\CreateMedicineFromSupplierCatalogAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\SupplierEquivalenceStatus;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierProductEquivalence;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierOfferComparison;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-241 — le même produit sous deux noms : la règle (abréviations, unités,
 * ordre des mots), le dictionnaire du site, et ce qu'un humain a décidé.
 */
class SupplierProductEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    private string $actorUuid;

    /** @var array<int, SupplierCatalog> */
    private array $catalogs = [];

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

    public function test_an_abbreviation_and_a_word_order_make_one_row(): void
    {
        $this->line($this->supplier('A', 'Fournisseur A'), 'Paracetamol 500mg cp', '120');
        $this->line($this->supplier('B', 'Fournisseur B'), 'Comprimé paracétamol 500 mg', '110');

        $rows = $this->compare();

        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]['quotes']);
        $this->assertSame(110.0, (float) $rows[0]['best_price']);
        // Le libellé de l'autre fournisseur reste lisible sur la ligne.
        $this->assertContains('Comprimé paracétamol 500 mg', array_column($rows[0]['quotes'], 'label'));
    }

    public function test_a_line_without_its_dose_is_only_proposed_between_two_suppliers(): void
    {
        $a = $this->line($this->supplier('A', 'Fournisseur A'), 'Paracetamol cp', '120');
        $this->line($this->supplier('B', 'Fournisseur B'), 'Comprimé paracetamol 500mg', '110');

        $comparison = app(SupplierOfferComparison::class)->forSite();
        $rows = collect($comparison['medicines'])->keyBy('name');

        $this->assertCount(2, $rows);
        $this->assertSame(2, $comparison['to_reconcile']);
        $peer = $rows['Paracetamol cp']['peers'][0];
        $this->assertSame('Comprimé paracetamol 500mg', $peer['name']);
        $this->assertSame('RULE', $peer['source']);
        $this->assertSame(['Fournisseur B'], $peer['suppliers']);
        $this->assertSame($a->uuid, $rows['Comprimé paracetamol 500mg']['peers'][0]['item_uuid']);
    }

    public function test_two_lines_of_the_same_supplier_are_never_proposed_to_each_other(): void
    {
        $supplier = $this->supplier('A', 'Fournisseur A');
        $this->line($supplier, 'Arbitel 80mg cpr', '120');
        $this->line($supplier, 'Arbitel H 80mg cpr', '150');

        foreach ($this->compare() as $row) {
            $this->assertSame([], $row['peers'], $row['name']);
        }
    }

    public function test_saying_same_merges_the_rows_and_different_separates_them(): void
    {
        $a = $this->line($this->supplier('A', 'Fournisseur A'), 'Paracetamol cp', '120');
        $b = $this->line($this->supplier('B', 'Fournisseur B'), 'Comprimé paracetamol 500mg', '110');

        $this->decide(['item_uuid' => $a->uuid, 'status' => 'SAME', 'other_item_uuids' => [$b->uuid]])->assertOk();

        $rows = $this->compare();
        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]['quotes']);
        $this->assertSame([], $rows[0]['peers']);
        // Rien n'est créé au catalogue de la clinique.
        $this->assertSame(0, Medicine::query()->count());

        $equivalence = SupplierProductEquivalence::query()->sole();
        $this->assertSame(SupplierEquivalenceStatus::Same, $equivalence->status);
        $this->assertSame($this->actorUuid, $equivalence->external_decided_by_uuid);
        $this->assertNull($equivalence->decided_by);

        // « Séparer » : la même paire devient « deux produits », et la règle se tait.
        $this->decide(['item_uuid' => $b->uuid, 'status' => 'DIFFERENT', 'other_item_uuids' => [$a->uuid]])->assertOk();

        $rows = $this->compare();
        $this->assertCount(2, $rows);
        $this->assertSame([[], []], array_column($rows, 'peers'));
        $this->assertSame(SupplierEquivalenceStatus::Different, $equivalence->refresh()->status);
        $this->assertSame(1, SupplierProductEquivalence::query()->count());
    }

    public function test_different_splits_what_the_rule_had_merged(): void
    {
        $a = $this->line($this->supplier('A', 'Fournisseur A'), 'Sérum salé 0,9% 500ml', '900');
        $b = $this->line($this->supplier('B', 'Fournisseur B'), 'serum sale 500 ml 0.9%', '850');

        $this->assertCount(1, $this->compare());

        $this->decide(['item_uuid' => $a->uuid, 'status' => 'DIFFERENT', 'other_item_uuids' => [$b->uuid]])->assertOk();

        $this->assertCount(2, $this->compare());
    }

    public function test_refusing_a_clinic_suggestion_silences_it(): void
    {
        $medicine = $this->medicine('Alcool 1l 70°');
        $line = $this->line($this->supplier('B', 'Fournisseur B'), 'ALCOOL ETHYLIQUE 70% 1L', '7100');

        $this->assertNotEmpty(collect($this->compare())->firstWhere('in_clinic_catalog', false)['suggestions']);

        $this->decide(['item_uuid' => $line->uuid, 'status' => 'DIFFERENT', 'medicine_uuids' => [$medicine->uuid]])->assertOk();

        $this->assertSame([], collect($this->compare())->firstWhere('in_clinic_catalog', false)['suggestions']);
        // « Le même » face à un produit de la clinique est un rattachement, pas une décision ici.
        $this->decide(['item_uuid' => $line->uuid, 'status' => 'SAME', 'medicine_uuids' => [$medicine->uuid]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medicine_uuids');
    }

    public function test_deciding_requires_its_own_permission(): void
    {
        $a = $this->line($this->supplier('A', 'Fournisseur A'), 'Paracetamol cp', '120');
        $b = $this->line($this->supplier('B', 'Fournisseur B'), 'Comprimé paracetamol 500mg', '110');

        $this->decide(['item_uuid' => $a->uuid, 'status' => 'SAME', 'other_item_uuids' => [$b->uuid]], ['medicine_suppliers.view'])
            ->assertForbidden();

        $this->assertSame(0, SupplierProductEquivalence::query()->count());
    }

    public function test_a_site_abbreviation_changes_what_the_rule_reads(): void
    {
        $this->line($this->supplier('A', 'Fournisseur A'), 'PCM 500mg comprimé', '120');
        $this->line($this->supplier('B', 'Fournisseur B'), 'Paracetamol 500 mg cp', '110');

        $this->assertCount(2, $this->compare());

        $this->withHeaders($this->headers(['supplier_equivalences.manage']))
            ->postJson('/api/v1/super-admin/pharmacy/product-synonyms', ['term' => 'PCM', 'canonical' => 'Paracétamol'])
            ->assertOk()
            ->assertJsonPath('data.site.0.term', 'pcm')
            ->assertJsonPath('data.site.0.canonical', 'paracetamol');

        $this->app->forgetScopedInstances();
        $this->assertCount(1, $this->compare());

        $this->withHeaders($this->headers(['supplier_equivalences.manage']))
            ->postJson('/api/v1/super-admin/pharmacy/product-synonyms', ['term' => '500', 'canonical' => 'cinq cents'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('term');
    }

    public function test_ai_proposals_wait_for_a_human_and_never_overwrite_a_decision(): void
    {
        $a = $this->line($this->supplier('A', 'Fournisseur A'), 'Doliprane 500', '120');
        $b = $this->line($this->supplier('B', 'Fournisseur B'), 'Paracetamol 500mg', '110');
        $c = $this->line($this->supplier('C', 'Fournisseur C'), 'Efferalgan 500', '130');

        $this->decide(['item_uuid' => $a->uuid, 'status' => 'DIFFERENT', 'other_item_uuids' => [$c->uuid]])->assertOk();

        $this->withHeaders($this->headers(['supplier_equivalences.manage']))
            ->postJson('/api/v1/super-admin/pharmacy/product-equivalences/proposals', ['pairs' => [
                ['item_uuid' => $a->uuid, 'other_item_uuid' => $b->uuid, 'reason' => 'Doliprane est du paracétamol.'],
                ['item_uuid' => $a->uuid, 'other_item_uuid' => $c->uuid],
            ]])
            ->assertOk()
            ->assertJsonPath('data.proposed', 1);

        $this->assertSame(SupplierEquivalenceStatus::Different, SupplierProductEquivalence::query()->where('other_label', 'Efferalgan 500')->orWhere('label', 'Efferalgan 500')->sole()->status);

        $comparison = app(SupplierOfferComparison::class)->forSite();
        $doliprane = collect($comparison['medicines'])->firstWhere('name', 'Doliprane 500');
        $this->assertSame('AI', $doliprane['peers'][0]['source']);
        $this->assertSame('Doliprane est du paracétamol.', $doliprane['peers'][0]['reason']);
        $this->assertSame(1, $comparison['proposed_by_ai']);
        // Une proposition ne réunit rien tant qu'un humain ne l'a pas confirmée.
        $this->assertCount(3, $comparison['medicines']);
    }

    public function test_ordering_a_line_said_to_be_the_same_reuses_the_clinic_product(): void
    {
        $a = $this->line($this->supplier('A', 'Fournisseur A'), 'Doliprane 500', '120');
        $b = $this->line($this->supplier('B', 'Fournisseur B'), 'Paracetamol 500mg', '110');
        $this->decide(['item_uuid' => $a->uuid, 'status' => 'SAME', 'other_item_uuids' => [$b->uuid]])->assertOk();

        // La pharmacie qui réceptionne fait entrer le produit (ADR-182).
        $this->seed(RolePermissionSeeder::class);
        $actor = CatalogActor::fromUser($this->pharmacist->fresh())->receivingDelivery();
        $first = app(CreateMedicineFromSupplierCatalogAction::class)->execute($a, $actor);

        $this->assertSame($first->id, app(CreateMedicineFromSupplierCatalogAction::class)->execute($b->refresh(), $actor)->id);
        $this->assertSame(1, Medicine::query()->count());
    }

    public function test_decisions_are_listed_and_can_be_forgotten(): void
    {
        $a = $this->line($this->supplier('A', 'Fournisseur A'), 'Paracetamol cp', '120');
        $b = $this->line($this->supplier('B', 'Fournisseur B'), 'Comprimé paracetamol 500mg', '110');
        $this->decide(['item_uuid' => $a->uuid, 'status' => 'SAME', 'other_item_uuids' => [$b->uuid]])->assertOk();

        $uuid = $this->withHeaders($this->headers(['medicine_suppliers.view']))
            ->getJson('/api/v1/super-admin/pharmacy/product-equivalences')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'SAME')
            ->assertJsonPath('data.0.decided_by', 'Direction centrale')
            ->json('data.0.uuid');

        $this->withHeaders($this->headers(['supplier_equivalences.manage']))
            ->deleteJson("/api/v1/super-admin/pharmacy/product-equivalences/{$uuid}")
            ->assertOk();

        $this->assertSame(0, SupplierProductEquivalence::query()->count());
        $this->assertCount(2, $this->compare());
    }

    // ------------------------------------------------------------------ outils

    /** @return array<int, array<string, mixed>> */
    private function compare(): array
    {
        return app(SupplierOfferComparison::class)->forSite()['medicines'];
    }

    /** @param  array<int, string>  $permissions */
    private function decide(array $payload, array $permissions = ['supplier_equivalences.manage'])
    {
        return $this->withHeaders($this->headers($permissions))->postJson('/api/v1/super-admin/pharmacy/product-equivalences', $payload);
    }

    /** @param  array<int, string>  $permissions */
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

    private function line(MedicineSupplier $supplier, string $label, ?string $price): SupplierCatalogItem
    {
        $catalog = $this->catalogs[$supplier->id] ??= SupplierCatalog::query()->create([
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
            'reference' => 'REF-'.(SupplierCatalogItem::query()->count() + 1),
            'medicine_label' => $label,
            'presentation' => 'Boîte',
            'supplier_price' => $price,
            'row_number' => SupplierCatalogItem::query()->count() + 1,
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
