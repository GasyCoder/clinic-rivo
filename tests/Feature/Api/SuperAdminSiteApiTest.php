<?php

namespace Tests\Feature\Api;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Models\AddressEntry;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MutualOrganization;
use App\Models\PaymentMethod;
use App\Models\PharmacyStockMovement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminSiteApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);

        $role = Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie']);
        $this->actor = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_stock_endpoint_is_authenticated_and_excludes_expired_quantity_from_availability(): void
    {
        $item = CatalogItem::query()->create([
            'code' => 'PARA-500',
            'name' => 'Paracétamol 500 mg',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Paracétamol',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        foreach ([
            ['number' => 'VALIDE', 'expires' => now()->addYear(), 'quantity' => 12],
            ['number' => 'PERIME', 'expires' => now()->subDay(), 'quantity' => 5],
        ] as $lot) {
            MedicineLot::query()->create([
                'medicine_id' => $medicine->id,
                'lot_number' => $lot['number'],
                'expires_at' => $lot['expires']->toDateString(),
                'quantity_on_hand' => $lot['quantity'],
                'active' => true,
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
            ]);
        }

        $this->getJson('/api/v1/super-admin/pharmacy/stock')
            ->assertUnauthorized();

        $this->withHeaders($this->headers())->getJson('/api/v1/super-admin/pharmacy/stock')
            ->assertOk()
            ->assertJsonPath('meta.site.code', 'A')
            ->assertJsonPath('meta.scope', 'READ_ONLY')
            ->assertJsonPath('data.summary.medicines', 1)
            ->assertJsonPath('data.summary.available_quantity', 12)
            ->assertJsonPath('data.summary.expired_lots', 1)
            ->assertJsonPath('data.medicines.0.quantity_on_hand', 17)
            ->assertJsonPath('data.medicines.0.expired_quantity', 5)
            ->assertJsonPath('data.medicines.0.available_quantity', 12);
    }

    public function test_stock_excel_rows_create_audited_idempotent_opening_and_entry_movements(): void
    {
        $item = CatalogItem::query()->create([
            'code' => 'PARA-500',
            'name' => 'Paracétamol 500 mg',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Paracétamol',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $actorUuid = (string) Str::uuid();
        $openingKey = (string) Str::uuid();
        $opening = ['rows' => [[
            'code_medicament' => 'PARA-500',
            'numero_lot' => 'LOT-IMPORT-01',
            'operation' => 'STOCK_INITIAL',
            'quantite' => 10,
            'date_reception' => now()->toDateString(),
            'date_peremption' => now()->addYear()->toDateString(),
            'motif' => 'Reprise du stock initial vérifié',
        ]]];

        $this->withHeaders($this->headers($actorUuid, $openingKey))
            ->postJson('/api/v1/super-admin/pharmacy/stock/import', $opening)
            ->assertOk()
            ->assertJsonPath('data.rows', 1)
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.created_lots', 1);

        $this->withHeaders($this->headers($actorUuid, $openingKey))
            ->postJson('/api/v1/super-admin/pharmacy/stock/import', $opening)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $entry = $opening;
        $entry['rows'][0]['operation'] = 'ENTREE';
        $entry['rows'][0]['quantite'] = 5;
        $entry['rows'][0]['motif'] = 'Livraison fournisseur vérifiée';
        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid()))
            ->postJson('/api/v1/super-admin/pharmacy/stock/import', $entry)
            ->assertOk()
            ->assertJsonPath('data.updated_lots', 1);

        $this->assertDatabaseHas('medicine_lots', [
            'lot_number' => 'LOT-IMPORT-01',
            'quantity_on_hand' => 15,
            'external_created_by_uuid' => $actorUuid,
            'external_updated_by_uuid' => $actorUuid,
        ]);
        $this->assertDatabaseCount('pharmacy_stock_movements', 2);
        $this->assertSame(15, PharmacyStockMovement::query()->latest('id')->value('balance_after'));
        $this->assertDatabaseHas('pharmacy_stock_movements', [
            'type' => 'OPENING',
            'quantity_delta' => 10,
            'external_actor_uuid' => $actorUuid,
            'external_actor_name' => 'Direction centrale',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => PharmacyStockMovement::class,
            'action' => 'create',
            'external_actor_uuid' => $actorUuid,
        ]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid()))
            ->postJson('/api/v1/super-admin/pharmacy/stock/import', $opening)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rows.0.numero_lot');
        $this->assertSame(15, MedicineLot::query()->where('lot_number', 'LOT-IMPORT-01')->value('quantity_on_hand'));
    }

    public function test_address_commands_are_idempotent_audited_and_soft_deleted(): void
    {
        $idempotencyKey = (string) Str::uuid();
        $actorUuid = (string) Str::uuid();
        $headers = $this->headers($actorUuid, $idempotencyKey);

        $first = $this->withHeaders($headers)->postJson('/api/v1/super-admin/address-entries', [
            'label' => 'Ambondromamy centre',
        ])->assertCreated();
        $uuid = $first->json('data.uuid');

        $this->withHeaders($headers)->postJson('/api/v1/super-admin/address-entries', [
            'label' => 'Ambondromamy centre',
        ])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('address_entries', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'entity_uuid' => $uuid,
            'external_actor_uuid' => $actorUuid,
            'external_actor_name' => 'Direction centrale',
        ]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid()))
            ->deleteJson("/api/v1/super-admin/address-entries/{$uuid}", [
                'reason' => 'Libellé remplacé par une localité officielle',
            ])->assertOk();

        $this->assertSoftDeleted('address_entries', ['uuid' => $uuid]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid()))
            ->postJson("/api/v1/super-admin/address-entries/{$uuid}/restore")
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $this->assertNotNull(AddressEntry::query()->where('uuid', $uuid)->first());
        $this->assertDatabaseHas('audit_logs', ['action' => 'restore', 'entity_uuid' => $uuid]);
    }

    public function test_address_batch_import_is_normalized_idempotent_and_audited(): void
    {
        AddressEntry::query()->create([
            'label' => 'Ambondromamy centre',
            'active' => true,
        ]);
        $actorUuid = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();
        $headers = $this->headers($actorUuid, $idempotencyKey);

        $payload = [
            'labels' => [
                'Ambondromamy centre',
                '  Nouvelle localité  ',
                'nouvelle localite',
                'Quartier Nord',
            ],
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v1/super-admin/address-entries/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.received', 4)
            ->assertJsonPath('data.unique', 3)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.existing', 1);

        $this->withHeaders($headers)
            ->postJson('/api/v1/super-admin/address-entries/import', $payload)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('address_entries', 3);
        $this->assertDatabaseCount('api_idempotency_records', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'external_actor_uuid' => $actorUuid,
            'external_actor_name' => 'Direction centrale',
        ]);
    }

    public function test_catalog_commands_create_versioned_standard_and_mutual_tariffs_for_a_remote_actor(): void
    {
        $actorUuid = (string) Str::uuid();
        $permissions = [
            'catalog.items.view',
            'catalog.items.create',
            'catalog.items.update',
            'catalog.items.delete',
            'catalog.items.restore',
            'catalog.tariffs.view',
            'catalog.tariffs.create',
            'catalog.tariffs.update',
            'catalog.tariffs.archive',
        ];
        $payload = [
            'code' => 'ECHO-API',
            'name' => 'Échographie API',
            'type' => CatalogItemType::Service->value,
            'module' => CatalogModule::Medicine->value,
            'unit' => 'examen',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => 'MEDICINE_DIRECT',
            'care_requires_allergy_check' => false,
            'care_recommends_vitals' => false,
            'description' => 'Prestation créée depuis le portail central.',
            'tariff_amount' => '30000',
            'mutual_tariff_amount' => '25000',
            'tariff_reason' => 'Grilles initiales validées',
        ];
        $idempotencyKey = (string) Str::uuid();
        $headers = $this->headers($actorUuid, $idempotencyKey, $permissions);

        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/super-admin/catalog', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'ECHO-API')
            ->assertJsonPath('data.current_standard_tariff.amount', '30000.00')
            ->assertJsonPath('data.current_mutual_tariff.amount', '25000.00');
        $uuid = $created->json('data.uuid');

        $this->withHeaders($headers)
            ->postJson('/api/v1/super-admin/catalog', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('catalog_items', 1);
        $this->assertDatabaseCount('catalog_tariffs', 2);
        $this->assertDatabaseHas('catalog_items', [
            'uuid' => $uuid,
            'created_by' => null,
            'external_created_by_uuid' => $actorUuid,
            'external_created_by_name' => 'Direction centrale',
        ]);

        $this->withHeaders($this->headers($actorUuid, null, ['catalog.items.view', 'catalog.tariffs.view']))
            ->getJson('/api/v1/super-admin/catalog?status=ALL')
            ->assertOk()
            ->assertJsonPath('data.summary.active', 1)
            ->assertJsonPath('data.summary.without_mutual_tariff', 0)
            ->assertJsonPath('data.items.0.code', 'ECHO-API');

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->postJson("/api/v1/super-admin/catalog/{$uuid}/tariffs", [
                'tariff_category' => 'MUTUAL',
                'tariff_amount' => '27000',
                'reason' => 'Convention mutuelle révisée',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_mutual_tariff.amount', '27000.00');

        $this->assertDatabaseCount('catalog_tariffs', 3);
        $this->assertDatabaseHas('catalog_tariffs', [
            'tariff_category' => 'MUTUAL',
            'amount' => '25000.00',
            'active_key' => null,
            'external_ended_by_uuid' => $actorUuid,
        ]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->deleteJson("/api/v1/super-admin/catalog/{$uuid}", [
                'reason' => 'Prestation retirée du référentiel actif',
            ])->assertOk();
        $this->assertSoftDeleted('catalog_items', ['uuid' => $uuid]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->postJson("/api/v1/super-admin/catalog/{$uuid}/restore")
            ->assertOk();
        $this->assertNotNull(CatalogItem::query()->where('uuid', $uuid)->first());
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'catalog',
            'external_actor_uuid' => $actorUuid,
        ]);
    }

    public function test_catalog_api_rejects_a_remote_actor_without_the_granular_permission(): void
    {
        $this->withHeaders($this->headers((string) Str::uuid(), (string) Str::uuid(), ['catalog.items.view']))
            ->postJson('/api/v1/super-admin/catalog', [
                'code' => 'FORBIDDEN',
                'name' => 'Création interdite',
                'type' => 'SERVICE',
                'module' => 'MEDICINE',
                'unit' => 'acte',
                'billable' => true,
                'stockable' => false,
                'tariff_amount' => 10000,
                'tariff_reason' => 'Tentative sans permission',
            ])->assertForbidden();

        $this->assertDatabaseCount('catalog_items', 0);
        $this->assertDatabaseCount('catalog_tariffs', 0);
    }

    public function test_mutual_organizations_are_managed_per_site_with_permissions_audit_and_soft_delete(): void
    {
        $actorUuid = (string) Str::uuid();
        $permissions = [
            'mutual_organizations.view',
            'mutual_organizations.create',
            'mutual_organizations.update',
            'mutual_organizations.archive',
            'mutual_organizations.restore',
        ];
        $idempotencyKey = (string) Str::uuid();
        $headers = $this->headers($actorUuid, $idempotencyKey, $permissions);

        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/super-admin/mutual-organizations', ['name' => '  ADEFI  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ADEFI')
            ->assertJsonPath('data.active', true);
        $uuid = $created->json('data.uuid');

        $this->withHeaders($headers)
            ->postJson('/api/v1/super-admin/mutual-organizations', ['name' => '  ADEFI  '])
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('mutual_organizations', 1);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->putJson("/api/v1/super-admin/mutual-organizations/{$uuid}", ['name' => 'ADEFI Madagascar'])
            ->assertOk()
            ->assertJsonPath('data.name', 'ADEFI Madagascar');

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->deleteJson("/api/v1/super-admin/mutual-organizations/{$uuid}", [
                'reason' => 'Convention terminée par le partenaire',
            ])
            ->assertOk();
        $this->assertSoftDeleted('mutual_organizations', ['uuid' => $uuid]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->postJson("/api/v1/super-admin/mutual-organizations/{$uuid}/restore")
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $this->withHeaders($this->headers($actorUuid, null, ['mutual_organizations.view']))
            ->getJson('/api/v1/super-admin/mutual-organizations?status=ALL')
            ->assertOk()
            ->assertJsonPath('meta.summary.active', 1)
            ->assertJsonPath('data.0.name', 'ADEFI Madagascar');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restore',
            'entity_uuid' => $uuid,
            'external_actor_uuid' => $actorUuid,
        ]);
    }

    public function test_mutual_organization_api_rejects_missing_permissions_and_normalized_duplicates(): void
    {
        MutualOrganization::query()->create(['name' => 'Funhece', 'active' => true]);

        $this->withHeaders($this->headers(
            (string) Str::uuid(),
            (string) Str::uuid(),
            ['mutual_organizations.view'],
        ))->postJson('/api/v1/super-admin/mutual-organizations', ['name' => 'ADEFI'])
            ->assertForbidden();

        $this->withHeaders($this->headers(
            (string) Str::uuid(),
            (string) Str::uuid(),
            ['mutual_organizations.create'],
        ))->postJson('/api/v1/super-admin/mutual-organizations', ['name' => '  FUNHÈCE '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('mutual_organizations', 1);
    }

    public function test_cash_registers_are_managed_per_site_with_permissions_audit_and_soft_delete(): void
    {
        $actorUuid = (string) Str::uuid();
        $permissions = [
            'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
            'cash_registers.activate', 'cash_registers.deactivate',
            'cash_registers.archive', 'cash_registers.restore',
        ];

        $created = $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->postJson('/api/v1/super-admin/cash-registers', ['name' => '  Caisse 1  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Caisse 1')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.archived', false);
        $uuid = $created->json('data.uuid');

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->putJson("/api/v1/super-admin/cash-registers/{$uuid}", ['name' => 'Caisse principale'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Caisse principale');

        // Deactivating is independent from archiving: the register stays
        // out of the soft-delete trash but drops off the site's own picker.
        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->postJson("/api/v1/super-admin/cash-registers/{$uuid}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.archived', false);
        $this->assertDatabaseHas('cash_registers', ['uuid' => $uuid, 'active' => false, 'deleted_at' => null]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->postJson("/api/v1/super-admin/cash-registers/{$uuid}/activate")
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.archived', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'entity_uuid' => $uuid,
            'external_actor_uuid' => $actorUuid,
        ]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->deleteJson("/api/v1/super-admin/cash-registers/{$uuid}", ['reason' => 'Fusionnée avec une autre caisse'])
            ->assertOk();
        $this->assertSoftDeleted('cash_registers', ['uuid' => $uuid]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), $permissions))
            ->postJson("/api/v1/super-admin/cash-registers/{$uuid}/restore")
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $this->withHeaders($this->headers($actorUuid, null, ['cash_registers.view']))
            ->getJson('/api/v1/super-admin/cash-registers?status=ALL')
            ->assertOk()
            ->assertJsonPath('meta.summary.active', 1)
            ->assertJsonPath('data.0.name', 'Caisse principale');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restore',
            'entity_uuid' => $uuid,
            'external_actor_uuid' => $actorUuid,
        ]);
    }

    public function test_cash_register_api_rejects_missing_permissions_and_duplicates(): void
    {
        $register = CashRegister::query()->create(['name' => 'Caisse 1']);

        $this->withHeaders($this->headers(
            (string) Str::uuid(),
            (string) Str::uuid(),
            ['cash_registers.view'],
        ))->postJson('/api/v1/super-admin/cash-registers', ['name' => 'Caisse 2'])
            ->assertForbidden();

        $this->withHeaders($this->headers(
            (string) Str::uuid(),
            (string) Str::uuid(),
            ['cash_registers.view'],
        ))->postJson("/api/v1/super-admin/cash-registers/{$register->uuid}/deactivate")
            ->assertForbidden();
        $this->assertTrue($register->fresh()->active);

        $this->withHeaders($this->headers(
            (string) Str::uuid(),
            (string) Str::uuid(),
            ['cash_registers.create'],
        ))->postJson('/api/v1/super-admin/cash-registers', ['name' => '  caisse 1 '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('cash_registers', 1);
    }

    public function test_cash_registers_list_reports_the_active_session_of_each_register_independently(): void
    {
        // Regression test: CashRegister::activeSession() used to key its
        // where() clause off $this->id, but Eloquent builds an eager-loaded
        // relation (with()/loadMissing()) once from an id-less prototype
        // model — so with two or more registers in the same response, every
        // one of them silently reported no active session at all.
        $withSession = CashRegister::query()->create(['name' => 'Caisse 1']);
        $withoutSession = CashRegister::query()->create(['name' => 'Caisse 2']);
        $session = CashSession::query()->create([
            'session_number' => 'AC-000101',
            'active_key' => 'REGISTER_'.$withSession->id,
            'cash_register_id' => $withSession->id,
            'status' => 'OPEN',
            'opening_amount' => '5000.00',
            'opened_by' => $this->actor->id,
            'opened_at' => now(),
        ]);

        $this->withHeaders($this->headers(null, null, ['cash_registers.view']))
            ->getJson('/api/v1/super-admin/cash-registers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Caisse 1')
            ->assertJsonPath('data.0.session.session_number', $session->session_number)
            ->assertJsonPath('data.0.session.status', 'OPEN')
            ->assertJsonPath('data.1.name', 'Caisse 2')
            ->assertJsonPath('data.1.session', null);

        $this->withHeaders($this->headers(null, null, ['cash_registers.view']))
            ->getJson("/api/v1/super-admin/cash-registers/{$withoutSession->uuid}")
            ->assertOk()
            ->assertJsonPath('data.active_session', null);
    }

    public function test_super_admin_can_inspect_lock_unlock_and_close_a_site_cash_session(): void
    {
        $actorUuid = (string) Str::uuid();
        $register = CashRegister::query()->create(['name' => 'Caisse principale']);
        $session = CashSession::query()->create([
            'session_number' => 'AC-000901',
            'active_key' => 'REGISTER_'.$register->id,
            'cash_register_id' => $register->id,
            'status' => 'OPEN',
            'opening_amount' => '10000.00',
            'opened_by' => $this->actor->id,
            'opened_at' => now()->subHour(),
        ]);
        $cashMethod = PaymentMethod::query()->create([
            'code' => 'CASH-TEST',
            'name' => 'Espèces',
            'active' => true,
            'affects_cash_balance' => true,
        ]);
        CashMovement::query()->create([
            'cash_session_id' => $session->id,
            'payment_method_id' => $cashMethod->id,
            'type' => 'PAYMENT',
            'direction' => 'IN',
            'amount' => '2500.00',
            'affects_cash_balance' => true,
            'description' => 'Encaissement de contrôle',
            'recorded_by' => $this->actor->id,
            'occurred_at' => now()->subMinutes(20),
        ]);

        $this->withHeaders($this->headers($actorUuid, null, ['cash_registers.view']))
            ->getJson("/api/v1/super-admin/cash-registers/{$register->uuid}")
            ->assertOk()
            ->assertJsonPath('data.register.name', 'Caisse principale')
            ->assertJsonPath('data.active_session.session_number', 'AC-000901')
            ->assertJsonPath('data.active_session.opened_by', $this->actor->name)
            ->assertJsonPath('data.active_session.totals.expected_cash', '12500.00')
            ->assertJsonPath('data.movements.0.description', 'Encaissement de contrôle');

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['cash_registers.view']))
            ->postJson("/api/v1/super-admin/cash-registers/{$register->uuid}/session/lock", [
                'reason' => 'Contrôle de sécurité demandé',
            ])->assertForbidden();

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['cash_registers.lock']))
            ->postJson("/api/v1/super-admin/cash-registers/{$register->uuid}/session/lock", [
                'reason' => 'Contrôle de sécurité demandé',
            ])->assertOk();

        $this->assertDatabaseHas('cash_sessions', [
            'uuid' => $session->uuid,
            'status' => 'LOCKED',
            'active_key' => 'REGISTER_'.$register->id,
            'external_locked_by_uuid' => $actorUuid,
            'lock_reason' => 'Contrôle de sécurité demandé',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cash.lock',
            'entity_uuid' => $session->uuid,
            'external_actor_uuid' => $actorUuid,
        ]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['cash_registers.unlock']))
            ->postJson("/api/v1/super-admin/cash-registers/{$register->uuid}/session/unlock", [
                'reason' => 'Contrôle terminé et reprise autorisée',
            ])->assertOk();
        $this->assertDatabaseHas('cash_sessions', [
            'uuid' => $session->uuid,
            'status' => 'OPEN',
            'active_key' => 'REGISTER_'.$register->id,
            'external_unlocked_by_uuid' => $actorUuid,
        ]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['cash_registers.close']))
            ->postJson("/api/v1/super-admin/cash-registers/{$register->uuid}/session/close", [
                'actual_closing_amount' => '12400.00',
                'reason' => 'Clôture centrale après comptage physique',
            ])->assertOk();

        $this->assertDatabaseHas('cash_sessions', [
            'uuid' => $session->uuid,
            'status' => 'CLOSED',
            'active_key' => null,
            'expected_closing_amount' => '12500.00',
            'actual_closing_amount' => '12400.00',
            'variance_amount' => '-100.00',
            'external_closed_by_uuid' => $actorUuid,
            'closing_reason' => 'Clôture centrale après comptage physique',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cash.close',
            'entity_uuid' => $session->uuid,
            'external_actor_uuid' => $actorUuid,
            'reason' => 'Clôture centrale après comptage physique',
        ]);
    }

    public function test_bulk_commands_are_atomic_idempotent_authorized_and_audited(): void
    {
        $actorUuid = (string) Str::uuid();
        $addressOne = AddressEntry::query()->create(['label' => 'Quartier Nord', 'active' => true]);
        $addressTwo = AddressEntry::query()->create(['label' => 'Quartier Sud', 'active' => true]);
        $addressUuids = [$addressOne->uuid, $addressTwo->uuid];
        $archiveHeaders = $this->headers(
            $actorUuid,
            (string) Str::uuid(),
            ['address_entries.archive'],
        );

        $this->withHeaders($archiveHeaders)
            ->postJson('/api/v1/super-admin/address-entries/bulk/archive', [
                'uuids' => $addressUuids,
                'reason' => 'Référentiel remplacé après validation',
            ])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);
        $this->withHeaders($archiveHeaders)
            ->postJson('/api/v1/super-admin/address-entries/bulk/archive', [
                'uuids' => $addressUuids,
                'reason' => 'Référentiel remplacé après validation',
            ])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(2, AddressEntry::onlyTrashed()->count());

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['address_entries.restore']))
            ->postJson('/api/v1/super-admin/address-entries/bulk/restore', ['uuids' => $addressUuids])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['address_entries.archive']))
            ->postJson('/api/v1/super-admin/address-entries/bulk/archive', [
                'uuids' => [$addressOne->uuid, (string) Str::uuid()],
                'reason' => 'Test de cohérence atomique',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uuids');
        $this->assertSame(2, AddressEntry::query()->count());

        $catalogItems = collect(['CONSULT-BULK', 'ECHO-BULK'])->map(fn (string $code) => CatalogItem::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]));
        $catalogUuids = $catalogItems->pluck('uuid')->all();

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['catalog.items.delete']))
            ->postJson('/api/v1/super-admin/catalog/bulk/archive', [
                'uuids' => $catalogUuids,
                'reason' => 'Prestations retirées après validation',
            ])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);
        $this->assertSame(2, CatalogItem::onlyTrashed()->whereIn('uuid', $catalogUuids)->count());

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['catalog.items.restore']))
            ->postJson('/api/v1/super-admin/catalog/bulk/restore', ['uuids' => $catalogUuids])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);

        $organizations = collect(['ADEFI Bulk', 'BOA Bulk'])->map(
            fn (string $name) => MutualOrganization::query()->create(['name' => $name, 'active' => true]),
        );
        $organizationUuids = $organizations->pluck('uuid')->all();

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['mutual_organizations.archive']))
            ->postJson('/api/v1/super-admin/mutual-organizations/bulk/archive', [
                'uuids' => $organizationUuids,
                'reason' => 'Conventions clôturées par la direction',
            ])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);
        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['mutual_organizations.restore']))
            ->postJson('/api/v1/super-admin/mutual-organizations/bulk/restore', ['uuids' => $organizationUuids])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['catalog.items.view']))
            ->postJson('/api/v1/super-admin/catalog/bulk/archive', [
                'uuids' => $catalogUuids,
                'reason' => 'Tentative sans autorisation',
            ])
            ->assertForbidden();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restore',
            'entity_uuid' => $catalogItems->first()->uuid,
            'external_actor_uuid' => $actorUuid,
        ]);
    }

    public function test_tariff_and_mutual_organization_imports_are_versioned_and_authorized(): void
    {
        CatalogItem::query()->create([
            'code' => 'CONSULT-IMPORT',
            'name' => 'Consultation importée',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $actorUuid = (string) Str::uuid();

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), [
            'catalog.tariffs.import', 'catalog.tariffs.create',
        ]))->postJson('/api/v1/super-admin/catalog/tariffs/import', [
            'rows' => [[
                'code' => 'CONSULT-IMPORT',
                'standard_amount' => '20000.00',
                'mutual_amount' => '18000.00',
                'reason' => 'Grille contractuelle validée',
            ]],
        ])->assertOk()->assertJsonPath('data.changed', 2);

        $this->assertDatabaseHas('catalog_tariffs', [
            'tariff_category' => 'STANDARD', 'amount' => '20000.00',
            'external_created_by_uuid' => $actorUuid,
        ]);
        $this->assertDatabaseHas('catalog_tariffs', [
            'tariff_category' => 'MUTUAL', 'amount' => '18000.00',
            'external_created_by_uuid' => $actorUuid,
        ]);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), [
            'mutual_organizations.import',
        ]))->postJson('/api/v1/super-admin/mutual-organizations/import', [
            'rows' => [
                ['name' => 'ADEFI', 'coverage_rate' => '100.00'],
                ['name' => 'BOA', 'coverage_rate' => '80.00'],
            ],
        ])->assertOk()->assertJsonPath('data.created', 2);

        $this->assertDatabaseHas('mutual_organizations', ['name' => 'ADEFI', 'coverage_rate' => '100.00']);
        $this->assertDatabaseHas('mutual_organizations', ['name' => 'BOA', 'coverage_rate' => '80.00']);

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['catalog.items.view']))
            ->postJson('/api/v1/super-admin/mutual-organizations/import', [
                'rows' => [['name' => 'BNI', 'coverage_rate' => '100.00']],
            ])->assertForbidden();
    }

    /** @return array<string, string> */
    private function headers(
        ?string $actorUuid = null,
        ?string $idempotencyKey = null,
        array $permissions = [],
    ): array {
        return array_filter([
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }
}
