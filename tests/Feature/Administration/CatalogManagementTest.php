<?php

namespace Tests\Feature\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Catalog\CatalogTariffReason;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    private function catalogManager(): User
    {
        $role = Role::query()->where('code', 'ADMINISTRATION')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->where('name', 'like', 'catalog.%')->pluck('id'),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array<string, mixed> */
    private function servicePayload(array $overrides = []): array
    {
        return array_replace([
            'code' => 'CONSULT-GEN',
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service->value,
            'module' => CatalogModule::Medicine->value,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::CareThenMedicine->value,
            'description' => 'Consultation médicale générale',
            'tariff_amount' => '15000.00',
            'tariff_reason' => 'Tarif initial validé',
        ], $overrides);
    }

    public function test_super_admin_cannot_open_the_site_catalog_and_access_requires_an_operational_account(): void
    {
        $superAdmin = $this->user('SUPER_ADMIN');

        $this->actingAs($superAdmin)->get('/administration/catalog')
            ->assertRedirect('/login');

        foreach (['ADMINISTRATION', 'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'] as $roleCode) {
            $this->actingAs($this->user($roleCode))
                ->get('/administration/catalog')
                ->assertForbidden();
        }

        $this->actingAs($this->catalogManager())->get('/administration/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Index')
                ->where('context.mode', 'site')
                ->count('sites', 1)
                ->where('sites.0.ok', true)
                ->where('sites.0.site.code', config('rivo.site.code'))
                // Les mutuelles se règlent au portail (ADR-045) : rien n'en est servi au site.
                ->has('sites.0.data.mutual_organizations', 0)
                ->where('sites.0.data.mutual_organizations_summary', null));
    }

    public function test_authorized_operational_account_creates_a_service_with_an_audited_initial_tariff(): void
    {
        $actor = $this->catalogManager();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())
            ->assertRedirect()
            ->assertSessionHas('status');

        $item = CatalogItem::query()->sole();
        $tariff = CatalogTariff::query()->sole();

        $this->assertNotNull($item->uuid);
        $this->assertSame(CatalogItemType::Service, $item->type);
        $this->assertTrue($item->billable);
        $this->assertFalse($item->stockable);
        $this->assertTrue($item->reception_selectable);
        $this->assertSame(ReceptionRoutingMode::CareThenMedicine, $item->reception_routing_mode);
        $this->assertSame('15000.00', $tariff->amount);
        $this->assertSame('CURRENT', $tariff->active_key);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'entity_id' => $item->id,
            'entity_type' => CatalogItem::class,
            'user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'entity_id' => $tariff->id,
            'entity_type' => CatalogTariff::class,
            'user_id' => $actor->id,
        ]);
    }

    public function test_care_service_requirements_are_configurable_but_forbidden_on_other_modules(): void
    {
        $actor = $this->catalogManager();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'code' => 'INJECTION-TEST',
            'name' => 'Injection de test',
            'module' => CatalogModule::Care->value,
            'reception_routing_mode' => ReceptionRoutingMode::CareOnly->value,
            'care_requires_allergy_check' => true,
            'care_recommends_vitals' => false,
        ]))->assertRedirect();

        $item = CatalogItem::query()->sole();
        $this->assertTrue($item->care_requires_allergy_check);
        $this->assertFalse($item->care_recommends_vitals);

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'code' => 'ECG-INVALID',
            'care_requires_allergy_check' => true,
        ]))->assertSessionHasErrors('care_requires_allergy_check');

        $this->assertDatabaseCount('catalog_items', 1);
    }

    public function test_authorized_account_can_create_distinct_standard_and_mutual_tariffs(): void
    {
        $actor = $this->catalogManager();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'tariff_amount' => '10000.00',
            'mutual_tariff_amount' => '20000.00',
            'tariff_reason' => 'Grilles initiales validées',
        ]))->assertRedirect()->assertSessionHas('status');

        $item = CatalogItem::query()->sole();
        $standard = $item->currentStandardTariff()->sole();
        $mutual = $item->currentMutualTariff()->sole();

        $this->assertSame(CatalogTariffCategory::Standard, $standard->tariff_category);
        $this->assertSame('10000.00', $standard->amount);
        $this->assertSame('CURRENT', $standard->active_key);
        $this->assertSame(CatalogTariffCategory::Mutual, $mutual->tariff_category);
        $this->assertSame('20000.00', $mutual->amount);
        $this->assertSame('CURRENT', $mutual->active_key);
        $this->assertDatabaseCount('catalog_tariffs', 2);

        foreach ([$standard, $mutual] as $tariff) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'create',
                'module' => 'catalog',
                'entity_id' => $tariff->id,
                'entity_type' => CatalogTariff::class,
                'user_id' => $actor->id,
            ]);
        }
    }

    public function test_catalog_type_rules_are_enforced_by_the_domain_action(): void
    {
        $actor = $this->catalogManager();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'billable' => false,
            'tariff_amount' => null,
            'tariff_reason' => null,
        ]))->assertSessionHasErrors('billable');

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'code' => 'MED-PARA',
            'name' => 'Paracétamol',
            'type' => CatalogItemType::Medicine->value,
            'module' => CatalogModule::Pharmacy->value,
            'stockable' => false,
        ]))->assertSessionHasErrors('stockable');

        $this->assertDatabaseCount('catalog_items', 0);
    }

    public function test_direct_reception_routes_must_match_the_catalog_module(): void
    {
        $actor = $this->catalogManager();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'code' => 'LAB-INVALID-ROUTE',
            'module' => CatalogModule::Laboratory->value,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect->value,
        ]))->assertSessionHasErrors('reception_routing_mode');

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'code' => 'MAT-INVALID-ROUTE',
            'module' => CatalogModule::Medicine->value,
            'reception_routing_mode' => ReceptionRoutingMode::MaternityDirect->value,
        ]))->assertSessionHasErrors('reception_routing_mode');

        $this->assertDatabaseCount('catalog_items', 0);
    }

    public function test_tariff_change_closes_the_old_version_and_never_overwrites_it(): void
    {
        $actor = $this->catalogManager();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())->assertRedirect();
        $item = CatalogItem::query()->sole();
        $oldTariff = CatalogTariff::query()->sole();

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff", [
            'tariff_amount' => '17500.00',
            'reason' => 'Révision annuelle validée',
        ])->assertRedirect();

        $oldTariff->refresh();
        $current = $item->fresh()->currentTariff()->sole();

        $this->assertSame('15000.00', $oldTariff->amount);
        $this->assertNull($oldTariff->active_key);
        $this->assertNotNull($oldTariff->effective_until);
        $this->assertSame('17500.00', $current->amount);
        $this->assertSame('CURRENT', $current->active_key);
        $this->assertDatabaseCount('catalog_tariffs', 2);
    }

    public function test_tariff_categories_are_updated_and_archived_independently(): void
    {
        $actor = $this->catalogManager();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'tariff_amount' => '10000.00',
            'mutual_tariff_amount' => '20000.00',
        ]))->assertRedirect();

        $item = CatalogItem::query()->sole();
        $originalStandard = $item->currentStandardTariff()->sole();
        $originalMutual = $item->currentMutualTariff()->sole();

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff", [
            'tariff_category' => CatalogTariffCategory::Mutual->value,
            'tariff_amount' => '22500.00',
            'reason' => 'Convention mutuelle révisée',
        ])->assertRedirect();

        $originalMutual->refresh();
        $this->assertNull($originalMutual->active_key);
        $this->assertNotNull($originalMutual->effective_until);
        $this->assertSame('10000.00', $item->fresh()->currentStandardTariff()->sole()->amount);
        $this->assertSame('22500.00', $item->fresh()->currentMutualTariff()->sole()->amount);

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff/archive", [
            'tariff_category' => CatalogTariffCategory::Standard->value,
            'reason' => 'Grille standard suspendue',
        ])->assertRedirect();

        $originalStandard->refresh();
        $this->assertNull($originalStandard->active_key);
        $this->assertNotNull($originalStandard->effective_until);
        $this->assertNull($item->fresh()->currentStandardTariff()->first());
        $this->assertSame('22500.00', $item->fresh()->currentMutualTariff()->sole()->amount);
        $this->assertDatabaseCount('catalog_tariffs', 3);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.tariff.archive',
            'module' => 'catalog',
            'entity_id' => $originalStandard->id,
            'reason' => 'Grille standard suspendue',
        ]);
    }

    public function test_tariff_and_item_can_be_suspended_and_archived_but_local_restore_is_forbidden(): void
    {
        $actor = $this->catalogManager();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())->assertRedirect();
        $item = CatalogItem::query()->sole();

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff/archive", [
            'reason' => 'Tarif en cours de validation',
        ])->assertRedirect();

        $this->assertNull($item->fresh()->currentTariff()->first());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.tariff.archive',
            'module' => 'catalog',
            'reason' => 'Tarif en cours de validation',
        ]);

        $this->actingAs($actor)->delete("/administration/catalog/{$item->uuid}", [
            'reason' => 'Prestation retirée du catalogue',
        ])->assertRedirect();

        $this->assertSoftDeleted('catalog_items', [
            'id' => $item->id,
            'deleted_by' => $actor->id,
            'delete_reason' => 'Prestation retirée du catalogue',
        ]);

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/restore")
            ->assertForbidden();

        $this->assertSoftDeleted('catalog_items', ['id' => $item->id]);
    }

    public function test_item_view_permission_does_not_expose_tariff_data_without_tariff_view(): void
    {
        $catalogManager = $this->catalogManager();
        $this->actingAs($catalogManager)->post('/administration/catalog', $this->servicePayload())->assertRedirect();

        $role = Role::query()->create(['code' => 'CATALOG_VIEWER', 'name' => 'Lecteur catalogue']);
        $role->permissions()->attach(Permission::query()->where('name', 'catalog.items.view')->value('id'));
        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get('/administration/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sites.0.data.items.0.current_standard_tariff', null)
                ->where('sites.0.data.items.0.current_mutual_tariff', null)
                ->where('sites.0.data.items.0.tariffs_count', null)
                ->has('sites.0.data.items.0.tariffs', 0)
                ->where('sites.0.data.summary.without_standard_tariff', null)
                ->where('sites.0.data.summary.without_mutual_tariff', null));
    }

    public function test_catalog_inertia_payload_exposes_both_current_tariffs_and_their_categories(): void
    {
        $actor = $this->catalogManager();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'tariff_amount' => '10000.00',
            'mutual_tariff_amount' => '20000.00',
        ]))->assertRedirect();

        $this->actingAs($actor)->get('/administration/catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Index')
                ->has('sites.0.data.options.tariff_categories', 2)
                ->where('sites.0.data.options.tariff_categories.0.value', CatalogTariffCategory::Standard->value)
                ->where('sites.0.data.options.tariff_categories.0.label', 'Sans mutuelle')
                ->where('sites.0.data.options.tariff_categories.1.value', CatalogTariffCategory::Mutual->value)
                ->where('sites.0.data.options.tariff_categories.1.label', 'Mutuelle')
                ->where('sites.0.data.items.0.current_standard_tariff.tariff_category', CatalogTariffCategory::Standard->value)
                ->where('sites.0.data.items.0.current_standard_tariff.tariff_category_label', 'Sans mutuelle')
                ->where('sites.0.data.items.0.current_standard_tariff.amount', '10000.00')
                ->where('sites.0.data.items.0.current_mutual_tariff.tariff_category', CatalogTariffCategory::Mutual->value)
                ->where('sites.0.data.items.0.current_mutual_tariff.tariff_category_label', 'Mutuelle')
                ->where('sites.0.data.items.0.current_mutual_tariff.amount', '20000.00')
                ->where('sites.0.data.items.0.care_requires_allergy_check', false)
                ->where('sites.0.data.items.0.care_recommends_vitals', false)
                ->has('sites.0.data.items.0.tariffs', 2)
                ->where('sites.0.data.summary.without_standard_tariff', 0)
                ->where('sites.0.data.summary.without_mutual_tariff', 0));
    }

    public function test_catalog_routes_use_uuid_not_local_numeric_ids(): void
    {
        $actor = $this->catalogManager();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())->assertRedirect();
        $item = CatalogItem::query()->sole();

        $this->actingAs($actor)->put("/administration/catalog/{$item->id}", [
            'name' => 'Tentative par ID local',
            'module' => CatalogModule::Medicine->value,
            'unit' => 'acte',
        ])->assertNotFound();
    }

    /**
     * ADR-044, amendement du 2026-09-28 (ter) — le site ouvre la même fiche que
     * le portail : pages de création et de modification, et retour à la
     * catégorie de la désignation créée.
     */
    public function test_the_site_opens_the_same_item_pages_as_the_portal(): void
    {
        $actor = $this->catalogManager();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())
            ->assertRedirect('/administration/catalog?module=MEDICINE&q=CONSULT-GEN')
            ->assertSessionHas('status', 'Désignation CONSULT-GEN créée.');
        $item = CatalogItem::query()->sole();

        $this->actingAs($actor)->get('/administration/catalog/create?module=IMAGING:ULTRASOUND')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/ItemForm')
                ->where('context.mode', 'site')
                ->where('targetSite.code', config('rivo.site.code'))
                ->where('item', null)
                ->where('category', 'IMAGING:ULTRASOUND')
                ->where('options.tariff_categories.0.value', CatalogTariffCategory::Standard->value)
                ->where('siteError', null));

        $this->actingAs($actor)->get("/administration/catalog/{$item->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/ItemForm')
                ->where('context.mode', 'site')
                ->where('item.code', 'CONSULT-GEN')
                ->where('item.current_standard_tariff.amount', '15000.00')
                ->where('item.tariffs.0.change_reason', 'Tarif initial validé')
                // Une consultation de Médecine ne reçoit pas de matériel habituel (ADR-072).
                ->where('item.accepts_consumables', false)
                ->has('consumableOptions', 0));

        // Une désignation archivée reste lisible sur sa page : on la restaure depuis là.
        $item->delete();
        $this->actingAs($actor)->get("/administration/catalog/{$item->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('item.archived', true));

        $this->actingAs($actor)->get('/administration/catalog/create?module=../../etc')->assertSessionHasErrors('module');
    }

    public function test_the_item_pages_keep_their_own_permissions(): void
    {
        $actor = $this->catalogManager();
        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload())->assertRedirect();
        $item = CatalogItem::query()->sole();

        $role = Role::query()->create(['code' => 'CATALOG_READER', 'name' => 'Lecteur catalogue']);
        $role->permissions()->attach(Permission::query()->where('name', 'catalog.items.view')->value('id'));
        $reader = User::factory()->create(['role_id' => $role->id]);

        // Lire une fiche n'est pas en créer une.
        $this->actingAs($reader)->get('/administration/catalog/create')->assertForbidden();
        $this->actingAs($reader)->get("/administration/catalog/{$item->uuid}/edit")
            ->assertOk()
            // Sans catalog.tariffs.view, aucun montant n'est servi.
            ->assertInertia(fn ($page) => $page
                ->where('item.current_standard_tariff', null)
                ->has('item.tariffs', 0));
        $this->actingAs($reader)->get("/administration/catalog/{$item->id}/edit")->assertNotFound();
    }

    /** Le motif automatique est écrit par le serveur du site, jamais par le navigateur. */
    public function test_the_automatic_reasons_are_written_by_the_site(): void
    {
        $actor = $this->catalogManager();

        $this->actingAs($actor)->post('/administration/catalog', $this->servicePayload([
            'tariff_reason_auto' => true,
            'tariff_reason' => 'texte forgé',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $item = CatalogItem::query()->sole();
        $this->assertSame(CatalogTariffReason::INITIAL, CatalogTariff::query()->sole()->change_reason);

        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff", [
            'tariff_category' => CatalogTariffCategory::Mutual->value,
            'tariff_amount' => '25000',
            'reason_auto' => true,
            'reason' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            'Tarif mutuelle fixé à 25 000 Ar depuis la fiche de la désignation (motif automatique).',
            $item->fresh()->currentMutualTariff->change_reason,
        );

        // Décochée, la case redonne la main : un motif vide est refusé.
        $this->actingAs($actor)->post("/administration/catalog/{$item->uuid}/tariff", [
            'tariff_category' => CatalogTariffCategory::Mutual->value,
            'tariff_amount' => '26000',
            'reason_auto' => false,
            'reason' => '',
        ])->assertSessionHasErrors('reason');
    }
}
