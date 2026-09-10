<?php

namespace Tests\Feature\Administration;

use App\Enums\CatalogItemType;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalServiceCatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClinicalServiceCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_CATALOG_ITEMS = 83;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_it_creates_reception_services_with_active_tariffs_and_a_real_audit_actor(): void
    {
        $actor = $this->catalogManager();
        config(['rivo.seeders.catalog_actor' => $actor->uuid]);

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $this->assertDatabaseCount('catalog_items', self::EXPECTED_CATALOG_ITEMS);
        $this->assertDatabaseCount('catalog_tariffs', 22);
        $this->assertSame(
            21,
            CatalogItem::query()
                ->where('type', CatalogItemType::Service->value)
                ->where('billable', true)
                ->where('stockable', false)
                ->whereHas('currentStandardTariff')
                ->count(),
        );
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'ECG',
            'name' => 'Électrocardiogramme (ECG)',
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'ECHO-ABD',
            'name' => 'Échographie abdominale',
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'CONSULT-GEN',
            'reception_routing_mode' => ReceptionRoutingMode::CareThenMedicine->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'CONSULT-SPEC',
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'INJECTION-IM',
            'reception_routing_mode' => ReceptionRoutingMode::CareOnly->value,
            'care_requires_allergy_check' => true,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'INJECTION-IV',
            'care_requires_allergy_check' => true,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'PERFUSION',
            'care_requires_allergy_check' => true,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'PANSEMENT-C',
            'care_requires_allergy_check' => false,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'LAB-NFS',
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::LaboratoryDirect->value,
        ]);
        $this->assertSame(29, CatalogItem::query()->where('reception_selectable', true)->count());
        $this->assertSame(20, CatalogItem::query()->where('module', 'CARE')->count());
        $this->assertSame(30, CatalogItem::query()->where('module', 'SURGERY')->count());
        $this->assertSame(16, CatalogItem::query()->where('module', 'MATERNITY')->count());
        $this->assertSame(2, CatalogItem::query()->where('module', 'FAMILY_PLANNING')->count());
        $this->assertSame(5, CatalogItem::query()->where('module', 'OPHTHALMOLOGY')->count());
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'MAT-DELIVERY-SIMPLE',
            'module' => 'MATERNITY',
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MaternityDirect->value,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'MAT-CESAREAN-TWIN',
            'module' => 'MATERNITY',
            'reception_selectable' => false,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'SURG-APPENDICITE',
            'name' => 'Appendicite',
            'module' => 'SURGERY',
            'reception_selectable' => false,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'SURG-OTHER',
            'billable' => false,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'CARE-ABL-SONDE',
            'name' => 'Ablation sonde',
            'reception_selectable' => false,
        ]);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'CARE-OTHER',
            'name' => 'Autres',
            'billable' => false,
        ]);
        $this->assertDatabaseMissing('catalog_tariffs', [
            'catalog_item_id' => CatalogItem::query()->where('code', 'CARE-ABL-SONDE')->value('id'),
        ]);

        // Prestations et Tarifs (Clinique Saint Georges) — physical price sheet import.
        $cesarienne = CatalogItem::query()->where('code', 'SURG-CESARIENNE')->firstOrFail();
        $this->assertSame('650000.00', $cesarienne->currentStandardTariff->amount);
        $this->assertSame('800000.00', $cesarienne->currentMutualTariff->amount);
        $this->assertDatabaseHas('catalog_items', ['code' => 'SURG-CERCLAGE', 'module' => 'SURGERY']);
        $this->assertSame(
            '300000.00',
            CatalogItem::query()->where('code', 'SURG-CERCLAGE')->firstOrFail()->currentStandardTariff->amount,
        );
        $this->assertDatabaseHas('catalog_items', ['code' => 'SURG-RUPTURE-UTERINE', 'module' => 'SURGERY']);
        $this->assertDatabaseHas('catalog_items', ['code' => 'SURG-PLACENTA-PRAEVIA', 'module' => 'SURGERY']);
        $this->assertDatabaseHas('catalog_items', ['code' => 'PANSEMENT-S-INT', 'module' => 'CARE']);
        $this->assertDatabaseHas('catalog_items', ['code' => 'PANSEMENT-C-INT', 'module' => 'CARE']);
        $this->assertDatabaseHas('catalog_items', [
            'code' => 'MAT-CONSULT-PRENATAL-SUIVI',
            'module' => 'MATERNITY',
            'reception_selectable' => true,
        ]);
        $this->assertDatabaseHas('catalog_items', ['code' => 'FP-INJECTABLE', 'module' => 'FAMILY_PLANNING']);
        $this->assertDatabaseHas('catalog_items', ['code' => 'FP-PILPLAN', 'module' => 'FAMILY_PLANNING']);
        $this->assertDatabaseHas('catalog_items', ['code' => 'OPHT-CONSULT', 'module' => 'OPHTHALMOLOGY']);
        $this->assertDatabaseHas('catalog_items', ['code' => 'OPHT-LUNETTE-T1', 'module' => 'OPHTHALMOLOGY']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'user_id' => $actor->id,
            'entity_type' => CatalogItem::class,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'catalog',
            'user_id' => $actor->id,
            'entity_type' => CatalogTariff::class,
        ]);
    }

    public function test_it_is_idempotent_and_preserves_a_manually_changed_tariff(): void
    {
        $actor = $this->catalogManager();
        config(['rivo.seeders.catalog_actor' => $actor->email]);

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $ecg = CatalogItem::query()->where('code', 'ECG')->firstOrFail();
        $ecg->currentTariff()->update(['amount' => '27500.00']);

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $this->assertDatabaseCount('catalog_items', self::EXPECTED_CATALOG_ITEMS);
        $this->assertDatabaseCount('catalog_tariffs', 22);
        $this->assertSame('27500.00', $ecg->fresh()->currentTariff->amount);
    }

    public function test_it_corrects_only_the_previous_specialist_consultation_route(): void
    {
        $actor = $this->catalogManager();
        config(['rivo.seeders.catalog_actor' => $actor->email]);

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $specialistConsultation = CatalogItem::query()->where('code', 'CONSULT-SPEC')->firstOrFail();
        $specialistConsultation->forceFill([
            'reception_routing_mode' => ReceptionRoutingMode::CareThenMedicine,
        ])->save();

        $this->seed(ClinicalServiceCatalogSeeder::class);

        $this->assertSame(
            ReceptionRoutingMode::MedicineDirect,
            $specialistConsultation->fresh()->reception_routing_mode,
        );
        $this->assertSame($actor->id, $specialistConsultation->fresh()->updated_by);
    }

    public function test_explicit_provisioning_actor_without_catalog_permissions_is_rejected(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->firstOrFail();
        $actor = User::factory()->create(['role_id' => $role->id]);
        config(['rivo.seeders.catalog_actor' => $actor->uuid]);

        $this->assertFalse($actor->hasPermissionTo('catalog.items.create'));
        $this->assertFalse($actor->hasPermissionTo('catalog.items.update'));
        $this->assertFalse($actor->hasPermissionTo('catalog.tariffs.create'));

        try {
            $this->seed(ClinicalServiceCatalogSeeder::class);
            $this->fail('Le seeder aurait dû refuser l’acteur sans permissions catalogue.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('RIVO_CATALOG_SEED_ACTOR', $exception->getMessage());
            $this->assertStringContainsString('catalog.items.create', $exception->getMessage());
            $this->assertStringContainsString('catalog.items.update', $exception->getMessage());
            $this->assertStringContainsString('catalog.tariffs.create', $exception->getMessage());
        }

        $this->assertDatabaseCount('catalog_items', 0);
        $this->assertFalse($actor->fresh()->hasPermissionTo('catalog.items.create'));
        $this->assertFalse($actor->fresh()->hasPermissionTo('catalog.items.update'));
        $this->assertFalse($actor->fresh()->hasPermissionTo('catalog.tariffs.create'));
    }

    public function test_it_refuses_to_create_demo_accounts_when_no_authorized_actor_exists(): void
    {
        try {
            $this->seed(ClinicalServiceCatalogSeeder::class);
            $this->fail('Le seeder aurait dû exiger un compte autorisé existant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Aucun compte actif ne possède les permissions requises',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('users', 0);
    }

    private function catalogManager(): User
    {
        $role = Role::query()->where('code', 'ADMINISTRATION')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('name', [
                'catalog.items.create',
                'catalog.items.update',
                'catalog.tariffs.create',
            ])->pluck('id'),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }
}
