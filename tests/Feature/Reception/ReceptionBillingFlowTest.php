<?php

namespace Tests\Feature\Reception;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\BillableItem;
use App\Models\CashSession;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionBillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach ($names as $name) {
            $permission = Permission::query()->create(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function service(User $actor, string $name = 'ECG', string $amount = '25000.00'): CatalogItem
    {
        $item = CatalogItem::create([
            'code' => fake()->unique()->bothify('SRV-###'),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        CatalogTariff::create([
            'catalog_item_id' => $item->id,
            'amount' => $amount,
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif initial de test',
            'created_by' => $actor->id,
        ]);

        return $item;
    }

    private function patientData(array $extra = []): array
    {
        return [
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1992-04-14',
            'sex' => 'F',
            ...$extra,
        ];
    }

    public function test_reception_lists_only_billable_services_with_an_active_tariff(): void
    {
        $actor = $this->userWithPermissions(['episodes.create', 'billing.create', 'billing.validate']);
        $service = $this->service($actor, 'Échographie', '45000.00');

        $medicine = CatalogItem::create([
            'code' => 'MED-001',
            'name' => 'Paracétamol',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'boîte',
            'billable' => true,
            'stockable' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        CatalogTariff::create([
            'catalog_item_id' => $medicine->id,
            'amount' => '5000.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif test',
            'created_by' => $actor->id,
        ]);

        $this->actingAs($actor)->get('/reception/patients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->has('billingCatalog', 1)
                ->where('billingCatalog.0.uuid', $service->uuid)
                ->where('billingCatalog.0.tariff_amount', '45000.00'));
    }

    public function test_pay_later_creates_a_validated_invoice_without_payment_or_receipt(): void
    {
        $actor = $this->userWithPermissions([
            'episodes.create', 'billing.create', 'billing.validate', 'billing.print',
        ]);
        $service = $this->service($actor);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($actor)->post('/reception/patients', $this->patientData([
            'catalog_lines' => [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => 2,
                'unit_price' => '1.00', // ignored: prices are never client-owned
            ]],
            'payment_choice' => 'LATER',
        ]));

        $invoice = Invoice::query()->sole();
        $response->assertRedirect("/invoices/{$invoice->uuid}");
        $this->assertSame('VALIDATED', $invoice->status->value);
        $this->assertSame('50000.00', $invoice->total_amount);
        $this->assertSame('50000.00', $invoice->balance_amount);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('billable_items', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame('25000.00', BillableItem::query()->sole()->unit_price);
    }

    public function test_authorized_reception_must_select_a_service_or_explicitly_defer_it(): void
    {
        $actor = $this->userWithPermissions([
            'episodes.create', 'billing.create', 'billing.validate',
        ]);

        $this->actingAs($actor)->post('/reception/patients', $this->patientData())
            ->assertSessionHasErrors('catalog_lines');

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('episodes', 0);

        config(['rivo.site.code' => 'M']);
        $this->actingAs($actor)->post('/reception/patients', $this->patientData([
            'defer_designation' => true,
        ]))->assertRedirect();

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_pay_now_atomically_creates_paid_invoice_cash_movement_and_receipt(): void
    {
        $actor = $this->userWithPermissions([
            'episodes.create', 'billing.create', 'billing.validate',
            'payments.create', 'receipts.view',
        ]);
        $service = $this->service($actor, 'Consultation', '30000.00');
        $method = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Espèces',
            'active' => true,
            'affects_cash_balance' => true,
        ]);
        CashSession::create([
            'session_number' => 'CS-000001',
            'active_key' => 'SINGLE_OPEN_CASH',
            'status' => 'OPEN',
            'opening_amount' => '10000.00',
            'opened_by' => $actor->id,
            'opened_at' => now(),
        ]);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($actor)->post('/reception/patients', $this->patientData([
            'catalog_lines' => [['catalog_item_uuid' => $service->uuid, 'quantity' => 1]],
            'payment_choice' => 'NOW',
            'payment_method_id' => $method->id,
            'payment_reference' => 'ARRIVEE-001',
        ]));

        $invoice = Invoice::query()->sole();
        $receipt = $invoice->payments()->sole()->receipt()->sole();

        $response->assertRedirect("/receipts/{$receipt->uuid}");
        $this->assertSame('PAID', $invoice->status->value);
        $this->assertSame('30000.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->balance_amount);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.create',
            'module' => 'cash',
            'user_id' => $actor->id,
        ]);
    }

    public function test_pay_now_with_a_closed_cash_rolls_back_the_entire_arrival(): void
    {
        $actor = $this->userWithPermissions([
            'episodes.create', 'billing.create', 'billing.validate', 'payments.create',
        ]);
        $service = $this->service($actor);
        $method = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Espèces',
            'active' => true,
            'affects_cash_balance' => true,
        ]);
        config(['rivo.site.code' => 'M']);

        $this->actingAs($actor)->post('/reception/patients', $this->patientData([
            'catalog_lines' => [['catalog_item_uuid' => $service->uuid, 'quantity' => 1]],
            'payment_choice' => 'NOW',
            'payment_method_id' => $method->id,
        ]))->assertSessionHasErrors('cash_session');

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->assertDatabaseCount('billable_items', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_financial_failure_never_rolls_back_an_emergency_arrival(): void
    {
        $actor = $this->userWithPermissions([
            'episodes.create', 'billing.create', 'billing.validate', 'payments.create',
        ]);
        $service = $this->service($actor);
        $method = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Espèces',
            'active' => true,
            'affects_cash_balance' => true,
        ]);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($actor)->post('/reception/patients', $this->patientData([
            'is_emergency' => true,
            'catalog_lines' => [['catalog_item_uuid' => $service->uuid, 'quantity' => 1]],
            'payment_choice' => 'NOW',
            'payment_method_id' => $method->id,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('status', fn (string $status) => str_contains($status, 'Admission conservée'));
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseHas('episodes', [
            'priority' => 'EMERGENCY',
            'administrative_status' => 'ORIENTED',
        ]);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invoice_creation_from_arrival_requires_billing_permissions(): void
    {
        $actor = $this->userWithPermissions(['episodes.create']);
        $service = $this->service($actor);

        $this->actingAs($actor)->post('/reception/patients', $this->patientData([
            'catalog_lines' => [['catalog_item_uuid' => $service->uuid, 'quantity' => 1]],
            'payment_choice' => 'LATER',
        ]))->assertForbidden();

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_printable_invoice_requires_its_specific_permission(): void
    {
        $actor = $this->userWithPermissions([
            'episodes.create', 'billing.create', 'billing.validate',
        ]);
        $service = $this->service($actor);
        config(['rivo.site.code' => 'M']);

        $this->actingAs($actor)->post('/reception/patients', $this->patientData([
            'catalog_lines' => [['catalog_item_uuid' => $service->uuid, 'quantity' => 1]],
            'payment_choice' => 'LATER',
        ]));

        $invoice = Invoice::query()->sole();
        $this->actingAs($actor)->get("/invoices/{$invoice->uuid}")->assertForbidden();

        $permission = Permission::query()->create(['name' => 'billing.print']);
        $actor->role->permissions()->attach($permission);

        $this->actingAs($actor->fresh())->get("/invoices/{$invoice->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoices/Show')
                ->where('invoice.uuid', $invoice->uuid)
                ->where('invoice.lines.0.description', 'ECG'));
    }
}
