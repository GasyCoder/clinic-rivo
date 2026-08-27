<?php

namespace Tests\Feature\Pharmacy;

use App\Actions\Medicine\CreatePrescriptionAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PharmacyDispense;
use App\Models\PharmacyStockMovement;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PharmacyDispensingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'M',
            'rivo.site.name' => 'Mampikony',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
        $this->cashier = User::factory()->create([
            'role_id' => Role::query()->where('code', 'RECEPTION')->value('id'),
        ]);
        (new PaymentMethodSeeder)->run();
    }

    /** @return array{0: Medicine, 1: MedicineLot, 2: MedicineLot} */
    private function saleMedicine(bool $prescriptionRequired = false): array
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-TEST-001',
            'name' => 'Produit test traçable',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'unité',
            'billable' => true,
            'stockable' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'amount' => '100.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif opérationnel de test',
            'created_by' => $this->pharmacist->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'DCI test',
            'form' => MedicineForm::Tablet,
            'strength' => '100 mg',
            'minimum_stock' => 8,
            'prescription_required' => $prescriptionRequired,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
        $early = MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'FEFO-EARLY',
            'received_at' => now()->toDateString(),
            'expires_at' => now()->addMonths(3)->toDateString(),
            'quantity_on_hand' => 3,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
        $late = MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'FEFO-LATE',
            'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 10,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        return [$medicine, $early, $late];
    }

    public function test_external_counter_sale_has_its_own_authorized_page(): void
    {
        [$medicine] = $this->saleMedicine();

        $this->actingAs($this->pharmacist)
            ->get('/pharmacy/counter-sales/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/CounterSales/Create')
                ->where('navigation.can_view_stock', true)
                ->where('navigation.can_view_prescriptions', true)
                ->where('navigation.dispense_count', 0)
                ->has('medicines', 1)
                ->where('medicines.0.uuid', $medicine->uuid)
                ->where('medicines.0.available_quantity', 13)
                ->missing('medicines.0.lots'));

        $this->actingAs($this->cashier)
            ->get('/pharmacy/counter-sales/create')
            ->assertForbidden();
    }

    public function test_internal_prescription_is_invoiced_at_pharmacy_and_dispensed_from_its_fefo_reservations(): void
    {
        [$medicine, $early, $late] = $this->saleMedicine();
        $doctor = User::factory()->create([
            'role_id' => Role::query()->where('code', 'MEDICINE')->value('id'),
        ]);
        $patient = Patient::query()->create([
            'patient_number' => 'M-26-0900',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1990-05-12',
            'sex' => 'F',
        ]);
        $episode = Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => 'M-26-0900-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'IN_CARE',
            'started_at' => now(),
            'created_by' => $doctor->id,
        ]);
        $consultation = Consultation::query()->create([
            'episode_id' => $episode->id,
            'doctor_id' => $doctor->id,
            'reason' => 'Consultation avec traitement interne',
            'consulted_at' => now(),
        ]);

        $prescription = $this->app->make(CreatePrescriptionAction::class)->execute($consultation, [[
            'medicine_uuid' => $medicine->catalogItem->uuid,
            'quantity' => 5,
            'dosage' => '100 mg',
            'frequency' => 'Une fois par jour',
        ]], $doctor);
        $dispense = $prescription->pharmacyDispense()->with('lines')->sole();

        $this->assertSame(PharmacyDispenseStatus::AwaitingInvoice, $dispense->status);
        $this->assertNull($dispense->invoice_id);
        $this->assertSame(3, MedicineStockReservation::query()
            ->where('medicine_lot_id', $early->id)
            ->sole()
            ->remaining_quantity);
        $this->assertSame(2, MedicineStockReservation::query()
            ->where('medicine_lot_id', $late->id)
            ->sole()
            ->remaining_quantity);

        $this->actingAs($this->pharmacist)
            ->post("/pharmacy/dispenses/{$dispense->uuid}/invoice")
            ->assertRedirect();
        $dispense->refresh()->load('invoice', 'lines');

        $this->assertSame(PharmacyDispenseStatus::AwaitingPayment, $dispense->status);
        $this->assertSame($patient->id, $dispense->invoice->patient_id);
        $this->assertSame('500.00', $dispense->invoice->total_amount);

        $this->actingAs($this->cashier)->post('/cash/open', ['opening_amount' => '0'])->assertRedirect();
        $cash = PaymentMethod::query()->where('code', 'CASH')->sole();
        $this->actingAs($this->cashier)->post("/invoices/{$dispense->invoice->uuid}/payments", [
            'invoice_uuid' => $dispense->invoice->uuid,
            'payment_method_id' => $cash->id,
            'amount' => '500.00',
        ])->assertRedirect();

        $this->actingAs($this->pharmacist)->post("/pharmacy/dispenses/{$dispense->uuid}/deliveries", [
            'lines' => [['id' => $dispense->lines->sole()->id, 'quantity' => 5]],
        ])->assertRedirect();

        $this->assertSame(PharmacyDispenseStatus::Dispensed, $dispense->fresh()->status);
        $this->assertSame(0, $early->fresh()->quantity_on_hand);
        $this->assertSame(8, $late->fresh()->quantity_on_hand);
        $this->assertSame(0, MedicineStockReservation::query()->sum('remaining_quantity'));
        $this->assertSame(2, MedicineStockReservation::query()
            ->where('status', MedicineStockReservationStatus::Dispensed->value)
            ->count());
    }

    public function test_external_sale_is_reserved_fefo_paid_only_at_cash_then_partially_dispensed(): void
    {
        [$medicine, $early, $late] = $this->saleMedicine();

        $this->actingAs($this->pharmacist)->post('/pharmacy/counter-sales', [
            'customer_name' => 'Client comptoir identifié',
            'customer_phone' => '034 00 000 00',
            'lines' => [['medicine_uuid' => $medicine->uuid, 'quantity' => 5]],
        ])->assertRedirect('/pharmacy?tab=dispenses')->assertSessionHas('status');

        $dispense = PharmacyDispense::query()->with(['invoice', 'lines.counterReservations'])->sole();
        $this->assertTrue(Str::isUuid($dispense->uuid));
        $this->assertSame(PharmacyDispenseStatus::AwaitingPayment, $dispense->status);
        $this->assertNull($dispense->invoice->patient_id);
        $this->assertSame('EXTERNAL', $dispense->invoice->customer_type);
        $this->assertSame('500.00', $dispense->invoice->total_amount);
        $this->assertSame(3, $dispense->lines->sole()->counterReservations->firstWhere('medicine_lot_id', $early->id)->remaining_quantity);
        $this->assertSame(2, $dispense->lines->sole()->counterReservations->firstWhere('medicine_lot_id', $late->id)->remaining_quantity);
        $this->assertSame(3, $early->fresh()->quantity_on_hand);
        $this->assertSame(10, $late->fresh()->quantity_on_hand);

        $this->actingAs($this->pharmacist)->get('/pharmacy')->assertInertia(fn ($page) => $page
            ->where('queue.dispenses.0.type', 'EXTERNAL')
            ->where('queue.dispenses.0.customer_name', 'Client comptoir identifié')
            ->where('queue.dispenses.0.customer_phone', '034 00 000 00')
            ->where('queue.dispenses.0.can_dispense', false));

        $this->actingAs($this->pharmacist)->post("/pharmacy/dispenses/{$dispense->uuid}/deliveries", [
            'lines' => [['id' => $dispense->lines->sole()->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->actingAs($this->cashier)->get('/cash')->assertInertia(fn ($page) => $page
            ->where('outstandingInvoices.0.patient', null)
            ->where('outstandingInvoices.0.customer_name', 'Client comptoir identifié')
            ->where('outstandingInvoices.0.source_module', 'PHARMACY'));
        $this->actingAs($this->cashier)->post('/cash/open', ['opening_amount' => '0'])->assertRedirect();
        $cash = PaymentMethod::query()->where('code', 'CASH')->sole();
        $this->actingAs($this->cashier)->post("/invoices/{$dispense->invoice->uuid}/payments", [
            'invoice_uuid' => $dispense->invoice->uuid,
            'payment_method_id' => $cash->id,
            'amount' => '500.00',
        ])->assertRedirect();
        $this->assertSame(PharmacyDispenseStatus::Ready, $dispense->fresh()->status);

        $this->actingAs($this->pharmacist)->post("/pharmacy/dispenses/{$dispense->uuid}/deliveries", [
            'lines' => [['id' => $dispense->lines->sole()->id, 'quantity' => 4]],
            'notes' => 'Première délivrance contrôlée',
        ])->assertRedirect();

        $this->assertSame(PharmacyDispenseStatus::PartiallyDispensed, $dispense->fresh()->status);
        $this->assertSame(0, $early->fresh()->quantity_on_hand);
        $this->assertSame(9, $late->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('pharmacy_dispense_events', 1);
        $this->assertDatabaseCount('pharmacy_dispense_allocations', 2);
        $this->assertSame(-4, PharmacyStockMovement::query()->sum('quantity_delta'));

        $payment = $dispense->invoice->payments()->sole();
        $this->actingAs($this->cashier)->post("/payments/{$payment->uuid}/cancel", [
            'reason' => 'Annulation tentée après le début de la délivrance',
        ])->assertSessionHasErrors('payment');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseCount('cash_movements', 1);

        $this->actingAs($this->pharmacist)->post("/pharmacy/dispenses/{$dispense->uuid}/deliveries", [
            'lines' => [['id' => $dispense->lines->sole()->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertSame(PharmacyDispenseStatus::Dispensed, $dispense->fresh()->status);
        $this->assertSame(8, $late->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pharmacy.dispense',
            'module' => 'pharmacy',
            'entity_id' => $dispense->id,
        ]);
        $this->assertDatabaseHas('pharmacy_stock_alerts', [
            'medicine_id' => $medicine->id,
            'active_key' => 'OPEN',
            'available_quantity' => 8,
            'threshold' => 8,
        ]);
    }

    public function test_external_prescription_is_required_for_a_restricted_medicine(): void
    {
        [$medicine] = $this->saleMedicine(true);

        $this->actingAs($this->pharmacist)->post('/pharmacy/counter-sales', [
            'lines' => [['medicine_uuid' => $medicine->uuid, 'quantity' => 1]],
        ])->assertSessionHasErrors('external_prescription_reference');

        $this->assertDatabaseCount('pharmacy_dispenses', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_adjustment_cannot_consume_reserved_units_and_is_audited_when_valid(): void
    {
        [$medicine, $early] = $this->saleMedicine();
        $this->actingAs($this->pharmacist)->post('/pharmacy/counter-sales', [
            'lines' => [['medicine_uuid' => $medicine->uuid, 'quantity' => 3]],
        ])->assertRedirect();

        $this->actingAs($this->pharmacist)->post('/pharmacy/stock/adjustments', [
            'lot_uuid' => $early->uuid,
            'type' => 'INVENTORY',
            'counted_quantity' => 2,
            'reason' => 'Comptage contradictoire vérifié',
        ])->assertSessionHasErrors('quantity');
        $this->assertSame(3, $early->fresh()->quantity_on_hand);

        $late = MedicineLot::query()->where('lot_number', 'FEFO-LATE')->sole();
        $this->actingAs($this->pharmacist)->post('/pharmacy/stock/adjustments', [
            'lot_uuid' => $late->uuid,
            'type' => 'BREAKAGE',
            'quantity' => 2,
            'reason' => 'Deux unités cassées constatées au contrôle',
        ])->assertRedirect();

        $movement = PharmacyStockMovement::query()->where('type', 'ADJUSTMENT')->sole();
        $this->assertSame(-2, $movement->quantity_delta);
        $this->assertSame(8, $late->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'pharmacy',
            'entity_type' => PharmacyStockMovement::class,
            'entity_id' => $movement->id,
        ]);
    }

    public function test_pharmacy_role_never_receives_cash_or_payment_permissions(): void
    {
        $pharmacyPermissions = Permission::query()
            ->whereHas('roles', fn ($query) => $query->where('code', 'PHARMACY'))
            ->pluck('name');

        $this->assertTrue($pharmacyPermissions->contains('pharmacy.counter_sales.create'));
        $this->assertFalse($pharmacyPermissions->contains(fn (string $name) => str_starts_with($name, 'cash.')));
        $this->assertFalse($pharmacyPermissions->contains(fn (string $name) => str_starts_with($name, 'payments.')));
    }
}
